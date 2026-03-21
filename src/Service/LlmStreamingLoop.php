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
 * tool calls from the iterator, invoking a plugin-provided tool executor,
 * and looping until a final text response or the maximum iteration limit
 * is reached. SSE events are emitted through the caller-provided AgUiState.
 */
class LlmStreamingLoop {

  /**
   * Constructs a new LlmStreamingLoop.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator service.
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
          $chatMsg->setToolsId($msg['tool_call_id']);
        }
        if (!empty($msg['tool_calls_raw'])) {
          // Attach the original tool calls so the provider can replay
          // them in the conversation context sent to the LLM API.
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

      // Instantiate the provider and configure the target model.
      $provider = $this->aiProvider->createInstance($config->providerId);
      $provider->setConfiguration(['model_id' => $config->modelId]);

      $streamedText = '';
      $messageStarted = FALSE;

      // Perform the streamed chat call. getNormalized() returns a
      // StreamedChatMessageIterator that assembles deltas incrementally.
      $chatOutput = $provider->chat($chatInput, $config->modelId);
      $iterator = $chatOutput->getNormalized();

      // Flush every token immediately for smooth real-time SSE delivery.
      // The default 100-char buffer causes chunks to arrive in bursts.
      if (method_exists($iterator, 'setMaxBufferSize')) {
        $iterator->setMaxBufferSize(1);
      }

      // Stream text chunks to the client. Delta buffering in AgUiState
      // batches tokens before sending to reduce SSE event count while
      // maintaining smooth streaming (flushes at 100 chars or 150ms).
      foreach ($iterator as $chunk) {
        $text = $chunk->getText() ?? '';
        if (!empty($text)) {
          if (!$messageStarted) {
            // Open the message envelope on the first text chunk.
            $agUiState->startMessage('assistant', $messageId);
            $messageStarted = TRUE;
          }
          $agUiState->addMessageContent($text, $messageId);
          $streamedText .= $text;
        }
      }

      // Close the text message envelope if any text was streamed.
      if ($messageStarted) {
        $agUiState->finishMessage($messageId);
      }

      // After the iterator is exhausted, getTools() returns the fully
      // assembled ToolsFunctionOutput objects from the delta stream.
      $assembledTools = $iterator->getTools();

      if (!empty($assembledTools)) {
        // Build execution and history arrays from the assembled tool calls.
        // $toolCallsForExec is passed to the plugin executor callback.
        // $toolCallsForHistory is stored in the message for the next call.
        $toolCallsForExec = [];
        $toolCallsForHistory = [];

        foreach ($assembledTools as $toolOutput) {
          $toolCallId = $toolOutput->getToolId() ?: $this->uuid->generate();
          $name = $toolOutput->getName();

          // Convert ToolsPropertyResult objects back to a plain array so
          // the plugin executor receives a simple key => value structure.
          $rawArgs = [];
          foreach ($toolOutput->getArguments() as $arg) {
            $rawArgs[$arg->getName()] = $arg->getValue();
          }

          $toolCallsForExec[$toolCallId] = [
            'name' => $name,
            'arguments' => $rawArgs,
          ];

          // Store the tool call in the format the Mistral provider expects
          // from getRenderedTools() / ToolCallFunction::fromArray().
          $toolCallsForHistory[] = [
            'id' => $toolCallId,
            'type' => 'function',
            'index' => 0,
            'function' => [
              'name' => $name,
              'arguments' => Json::encode($rawArgs),
            ],
          ];
        }

        // Emit start events for ALL tool calls before executing any of
        // them. This matches the AG-UI protocol expectation that all tool
        // call envelopes are opened before results are produced.
        foreach ($toolCallsForExec as $toolCallId => $toolCall) {
          $agUiState->startToolCall($toolCall['name'], NULL, $toolCallId);
        }

        // Invoke the plugin-provided executor with the full set of tool
        // calls. It returns an array of tool result messages.
        $toolResults = ($config->toolExecutor)($toolCallsForExec);

        // Emit finish events for ALL tool calls after the executor returns.
        foreach ($toolCallsForExec as $toolCallId => $toolCall) {
          $agUiState->finishToolCall($toolCallId);
        }

        // Append the assistant message (with its tool calls) and all tool
        // result messages to the running conversation history.
        $messages[] = [
          'role' => 'assistant',
          'content' => $streamedText ?: '',
          'tool_calls_raw' => $toolCallsForHistory,
        ];
        foreach ($toolResults as $result) {
          $messages[] = $result;
        }

        // Advance to the next iteration with a fresh message ID.
        $messageId = $this->uuid->generate();
        continue;
      }

      // No tool calls: the LLM produced a final text response. Store it
      // and exit the loop.
      $fullMessage = $streamedText;
      break;
    }

    return new LlmLoopResult($fullMessage, $messages);
  }

  /**
   * Wraps a raw tool call array into an object with getOutputRenderArray().
   *
   * ChatMessage::getRenderedTools() calls getOutputRenderArray() on each
   * tool. This wrapper satisfies that interface using the pre-built array
   * so stored tool call history can be replayed in subsequent LLM calls.
   *
   * @param array $data
   *   Tool call array with id, type, function (name + arguments).
   *
   * @return object
   *   An object with a getOutputRenderArray() method.
   */
  private static function wrapToolCallArray(array $data): object {
    return new class($data) {

      /**
       * Constructs the wrapper.
       */
      public function __construct(private array $data) {}

      /**
       * Returns the tool call render array.
       */
      public function getOutputRenderArray(): array {
        return $this->data;
      }

    };
  }

}
