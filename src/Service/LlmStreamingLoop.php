<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Swis\AgUiServer\AgUiState;

/**
 * Runs the agentic LLM tool-call loop for AI assistant plugins.
 *
 * Encapsulates the pattern of calling an LLM with streaming, assembling
 * tool calls from the streaming iterator, invoking a plugin-provided tool
 * executor closure, and looping until the LLM produces a final text
 * response or the maximum iteration limit is reached.
 *
 * SSE events (message start/delta/finish, tool call start/finish) are
 * emitted through the caller-provided AgUiState object, which implements
 * the AG-UI protocol. Text chunks reach the browser in real time via
 * Server-Sent Events.
 *
 * The service is stateless: all per-request state lives in the LlmLoopConfig
 * value object and in the local $messages array built up during the loop.
 * This means the same service instance can be reused safely across multiple
 * concurrent requests.
 *
 * Tool call protocol within a single loop iteration:
 * 1. Send conversation to the LLM (with tools definition).
 * 2. Stream text chunks to the client via AgUiState delta buffering.
 * 3. After the stream ends, inspect getTools() for tool calls.
 * 4. If tool calls are present:
 *    a. Emit startToolCall() for ALL tool calls before executing any.
 *    b. Invoke the plugin's toolExecutor closure with the call map.
 *    c. Emit finishToolCall() for all tool calls.
 *    d. Append the assistant message (with raw tool calls) and all tool
 *       result messages to the running history.
 *    e. Loop back to step 1 with the updated history.
 * 5. If no tool calls: store the final text and exit the loop.
 */
class LlmStreamingLoop {

  /**
   * Constructs a new LlmStreamingLoop.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager. Used to instantiate a provider plugin
   *   by ID (e.g. 'mistral') and configure it with the model ID before
   *   each LLM call.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator service. Used to generate unique message IDs for
   *   SSE message envelopes on each tool-call iteration so events can be
   *   correlated by the client.
   */
  public function __construct(
    private readonly AiProviderPluginManager $aiProvider,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * Runs the LLM call with tool loop and streams SSE events.
   *
   * Iterates up to $config->maxIterations times. On each iteration the
   * full conversation (including any tool call/result messages appended
   * in previous iterations) is sent to the LLM. Text chunks are forwarded
   * to the client immediately via AgUiState delta buffering. When the LLM
   * responds with tool calls, all startToolCall() events are emitted first,
   * the plugin executor is invoked, then all finishToolCall() events are
   * emitted. The loop continues until the LLM produces a text-only response
   * or the iteration limit is reached.
   *
   * @param \Swis\AgUiServer\AgUiState $agUiState
   *   The AG-UI state manager for SSE event emission. Created and owned
   *   by the caller; this method only reads/writes events through it.
   * @param \Drupal\oe_ai_assistant\Service\LlmLoopConfig $config
   *   The loop configuration: prompts, history, tools, provider, executor.
   *
   * @return \Drupal\oe_ai_assistant\Service\LlmLoopResult
   *   The result containing the final assistant text and the full message
   *   array (conversation history including tool call/result messages).
   */
  public function run(AgUiState $agUiState, LlmLoopConfig $config): LlmLoopResult {
    // Work on a local copy of the history so the original config is not
    // mutated. Tool call/result messages are appended here each iteration.
    $messages = $config->conversationHistory;
    $messageId = $config->messageId;
    // Accumulates the final assistant text (from the last non-tool iteration).
    $fullMessage = '';

    for ($i = 0; $i < $config->maxIterations; $i++) {
      // Build ChatMessage objects from the running message array. Tool
      // result messages need setToolsId() and assistant messages that
      // carried tool calls need setTools() so the provider re-sends them
      // correctly in the next API request.
      $chatMessages = [];
      foreach ($messages as $msg) {
        $chatMsg = new ChatMessage(
          $msg['role'],
          $msg['content'] ?? '',
        );
        if (!empty($msg['tool_call_id'])) {
          // Mark this message as a tool result for the given call ID.
          // The provider uses this to build the 'tool' role message in
          // the API request body.
          $chatMsg->setToolsId($msg['tool_call_id']);
        }
        if (!empty($msg['tool_calls_raw'])) {
          // Attach the original tool calls so the provider can replay
          // them in the conversation context sent to the LLM API. Each
          // element is wrapped via wrapToolCallArray() to satisfy the
          // interface expected by ChatMessage::getRenderedTools().
          $chatMsg->setTools(array_map(
            [self::class, 'wrapToolCallArray'],
            $msg['tool_calls_raw'],
          ));
        }
        $chatMessages[] = $chatMsg;
      }

      // Assemble and configure the ChatInput for this iteration.
      $chatInput = new ChatInput($chatMessages);
      $chatInput->setSystemPrompt($config->systemPrompt);
      $chatInput->setStreamedOutput(TRUE);
      $chatInput->setChatTools($config->tools);

      // Instantiate the provider plugin and apply the model configuration.
      // A new instance is created each iteration because provider plugins
      // may carry per-call state internally.
      $provider = $this->aiProvider->createInstance($config->providerId);
      $provider->setConfiguration(['model_id' => $config->modelId]);

      // Track whether any text was streamed so we know when to open/close
      // the SSE message envelope.
      $streamedText = '';
      $messageStarted = FALSE;

      // Perform the streamed chat call. getNormalized() returns a
      // StreamedChatMessageIterator that assembles delta chunks into complete
      // text and tool call objects incrementally.
      $chatOutput = $provider->chat($chatInput, $config->modelId);
      $iterator = $chatOutput->getNormalized();

      // Flush every token immediately for smooth real-time SSE delivery.
      // The default 100-char buffer causes chunks to arrive in bursts
      // rather than token by token, degrading the perceived streaming UX.
      if (method_exists($iterator, 'setMaxBufferSize')) {
        $iterator->setMaxBufferSize(1);
      }

      // Iterate the streaming response. Each chunk may carry a text delta.
      // AgUiState internally batches deltas before sending SSE events to
      // reduce event count while maintaining smooth streaming (flushes at
      // 100 chars or 150 ms, whichever comes first).
      foreach ($iterator as $chunk) {
        $text = $chunk->getText() ?? '';
        if (!empty($text)) {
          if (!$messageStarted) {
            // Open the SSE message envelope on the very first text chunk.
            // Subsequent chunks are added as deltas to the same envelope.
            $agUiState->startMessage('assistant', $messageId);
            $messageStarted = TRUE;
          }
          $agUiState->addMessageContent($text, $messageId);
          $streamedText .= $text;
        }

      }

      // Close the text message SSE envelope after the iterator is exhausted.
      if ($messageStarted) {
        $agUiState->finishMessage($messageId);
      }

      // After the iterator is fully consumed, getTools() returns the complete
      // set of ToolsFunctionOutput objects assembled from the delta stream.
      $assembledTools = $iterator->getTools();

      if (!empty($assembledTools)) {
        // Build two parallel structures from the assembled tool calls:
        // - $toolCallsForExec: passed to the plugin executor (simple arrays).
        // - $toolCallsForHistory: stored in the conversation history message
        //   so the Mistral provider can replay them in the next API call.
        $toolCallsForExec = [];
        $toolCallsForHistory = [];

        foreach ($assembledTools as $toolOutput) {
          // Use the provider-assigned tool call ID if available; otherwise
          // generate a UUID. The ID is used to correlate tool result messages
          // with their originating tool call.
          $toolCallId = $toolOutput->getToolId() ?: $this->uuid->generate();
          $name = $toolOutput->getName();

          // Convert ToolsPropertyResult objects back to a plain key => value
          // array so the plugin executor receives a simple structure that does
          // not depend on the Drupal AI module's internal types.
          $rawArgs = [];
          foreach ($toolOutput->getArguments() as $arg) {
            $rawArgs[$arg->getName()] = $arg->getValue();
          }

          // Executor-facing representation: keyed by call ID for easy lookup.
          $toolCallsForExec[$toolCallId] = [
            'name' => $name,
            'arguments' => $rawArgs,
          ];

          // History-facing representation: matches the structure that the
          // Mistral provider reconstructs from getRenderedTools() /
          // ToolCallFunction::fromArray() in subsequent API calls.
          $toolCallsForHistory[] = [
            'id' => $toolCallId,
            'type' => 'function',
            // The index is always 0 because Mistral's streaming chunks report
            // tool calls sequentially rather than with meaningful indices.
            'index' => 0,
            'function' => [
              'name' => $name,
              // Arguments must be JSON-encoded because the Mistral provider
              // stores and transmits them as a JSON string, not an object.
              'arguments' => Json::encode($rawArgs),
            ],
          ];
        }

        // Emit start events for ALL tool calls before executing any of them.
        // The AG-UI protocol expects all tool call envelopes to be opened
        // before any results are produced, so the client can render a
        // "running tools" indicator for each simultaneously.
        foreach ($toolCallsForExec as $toolCallId => $toolCall) {
          $agUiState->startToolCall($toolCall['name'], NULL, $toolCallId);
        }

        // Brief pause so the TOOL_CALL_START events reach the browser
        // before the executor begins emitting STATE_SNAPSHOT / STATE_DELTA
        // events. Without this, they coalesce into one TCP segment and
        // the client cannot show a spinner before the first delta arrives.
        usleep(15000);

        // Invoke the plugin-provided executor with the complete set of tool
        // calls. The executor is responsible for dispatching to the correct
        // handler function and returning tool result messages.
        $toolResults = ($config->toolExecutor)($toolCallsForExec);

        // Emit finish events for all tool calls after the executor returns.
        // These are emitted together (after all results are ready) because
        // the executor processes all calls before returning.
        foreach ($toolCallsForExec as $toolCallId => $toolCall) {
          $agUiState->finishToolCall($toolCallId);
        }

        // Append the assistant message (with its raw tool calls) to the
        // running history so the LLM receives the full context on the next
        // API call. The content is the text streamed before the tool calls
        // (often empty when the LLM jumps straight to tool calls).
        $messages[] = [
          'role' => 'assistant',
          'content' => $streamedText ?: '',
          'tool_calls_raw' => $toolCallsForHistory,
        ];
        // Append each tool result message returned by the executor.
        foreach ($toolResults as $result) {
          $messages[] = $result;
        }

        // Generate a fresh message ID for the next iteration's SSE envelope.
        $messageId = $this->uuid->generate();
        continue;
      }

      // No tool calls in this iteration: the LLM produced a final text
      // response. Store it and exit the loop normally.
      $fullMessage = $streamedText;
      break;
    }

    return new LlmLoopResult($fullMessage, $messages);
  }

  /**
   * Wraps a raw tool call array into an object with getOutputRenderArray().
   *
   * ChatMessage::getRenderedTools() calls getOutputRenderArray() on each
   * tool call object when building the API request payload for the next
   * LLM call. Stored tool calls are plain arrays (serialisable to TempStore);
   * this anonymous class wrapper satisfies the interface contract so the
   * pre-built arrays can be replayed in subsequent iterations without losing
   * any data.
   *
   * @param array $data
   *   Tool call array with keys: id, type, index, function (name +
   *   arguments). This is the exact structure expected by the Mistral
   *   provider's ToolCallFunction::fromArray().
   *
   * @return object
   *   An anonymous object with a single getOutputRenderArray() method
   *   that returns the original $data array unchanged.
   */
  private static function wrapToolCallArray(array $data): object {
    return new class($data) {

      /**
       * Constructs the wrapper with the tool call array.
       *
       * @param array $data
       *   The raw tool call data to be returned by getOutputRenderArray().
       */
      public function __construct(private array $data) {}

      /**
       * Returns the tool call render array.
       *
       * Called by ChatMessage::getRenderedTools() when assembling the
       * tools array for an API request to the LLM provider.
       *
       * @return array
       *   The raw tool call data array.
       */
      public function getOutputRenderArray(): array {
        return $this->data;
      }

    };
  }

}
