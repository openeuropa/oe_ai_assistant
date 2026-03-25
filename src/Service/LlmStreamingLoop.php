<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Swis\AgUiServer\AgUiState;
use function Cortex\JsonRepair\json_repair_decode;

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
   * full conversation is sent to the LLM. Text chunks are forwarded to
   * the client in real time. When the LLM responds with tool calls, the
   * plugin executor is invoked and the loop continues. The loop exits
   * when the LLM produces a text-only response.
   *
   * @param \Swis\AgUiServer\AgUiState $agUiState
   *   The AG-UI state manager for SSE event emission.
   * @param \Drupal\oe_ai_assistant\Service\LlmLoopConfig $config
   *   The loop configuration.
   *
   * @return \Drupal\oe_ai_assistant\Service\LlmLoopResult
   *   The final assistant text and full conversation history.
   */
  public function run(AgUiState $agUiState, LlmLoopConfig $config): LlmLoopResult {
    $messages = $config->conversationHistory;
    $messageId = $config->messageId;
    $fullMessage = '';

    for ($i = 0; $i < $config->maxIterations; $i++) {
      $chatInput = $this->buildChatInput($messages, $config);
      $iterator = $this->createStreamingIterator($chatInput, $config);

      // Stream text and detect partial tool call arguments.
      [$streamedText, $startedToolCalls] = $this->streamIteration(
        $iterator, $agUiState, $config, $messageId,
      );

      // After iteration, check for complete tool calls.
      $assembledTools = $iterator->getTools();

      if (!empty($assembledTools)) {
        [$toolCallsForExec, $toolCallsForHistory] =
          $this->assembleToolCalls($assembledTools);

        $this->emitToolCallEvents(
          $agUiState, $config, $toolCallsForExec, $startedToolCalls,
        );

        // Append assistant message and tool results to history.
        $messages[] = [
          'role' => 'assistant',
          'content' => $streamedText ?: '',
          'tool_calls_raw' => $toolCallsForHistory,
        ];
        $toolResults = ($config->toolExecutor)($toolCallsForExec);
        foreach ($toolResults as $result) {
          $messages[] = $result;
        }

        // Emit finish events for all tool calls.
        foreach ($toolCallsForExec as $toolCallId => $toolCall) {
          $agUiState->finishToolCall($toolCallId);
        }

        $messageId = $this->uuid->generate();
        continue;
      }

      // No tool calls: final text response.
      $fullMessage = $streamedText;
      break;
    }

    return new LlmLoopResult($fullMessage, $messages);
  }

  /**
   * Builds ChatMessage objects and a ChatInput from the message history.
   *
   * Converts the plain-array message history into ChatMessage objects
   * that the AI provider can send to the LLM API. Tool result messages
   * get setToolsId() and assistant messages with tool calls get
   * setTools() for proper replay.
   *
   * @param array $messages
   *   The running conversation history as plain arrays.
   * @param \Drupal\oe_ai_assistant\Service\LlmLoopConfig $config
   *   The loop configuration (system prompt, tools).
   *
   * @return \Drupal\ai\OperationType\Chat\ChatInput
   *   The assembled chat input ready for the provider.
   */
  private function buildChatInput(
    array $messages,
    LlmLoopConfig $config,
  ): ChatInput {
    $chatMessages = [];
    foreach ($messages as $msg) {
      $chatMsg = new ChatMessage(
        $msg['role'],
        $msg['content'] ?? '',
      );
      if (!empty($msg['tool_call_id'])) {
        $chatMsg->setToolsId($msg['tool_call_id']);
      }
      if (!empty($msg['tool_calls_raw'])) {
        $chatMsg->setTools(array_map(
          [self::class, 'wrapToolCallArray'],
          $msg['tool_calls_raw'],
        ));
      }
      $chatMessages[] = $chatMsg;
    }

    $chatInput = new ChatInput($chatMessages);
    $chatInput->setSystemPrompt($config->systemPrompt);
    $chatInput->setStreamedOutput(TRUE);
    $chatInput->setChatTools($config->tools);

    return $chatInput;
  }

  /**
   * Creates a streaming iterator from the AI provider.
   *
   * Instantiates the provider plugin, configures the model, performs
   * the streamed chat call, and sets the buffer size to 1 for
   * immediate token delivery.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $chatInput
   *   The assembled chat input.
   * @param \Drupal\oe_ai_assistant\Service\LlmLoopConfig $config
   *   The loop configuration (provider ID, model ID).
   *
   * @return \Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface
   *   The streaming iterator.
   */
  private function createStreamingIterator(
    ChatInput $chatInput,
    LlmLoopConfig $config,
  ) {
    $provider = $this->aiProvider->createInstance($config->providerId);
    $provider->setConfiguration(['model_id' => $config->modelId]);

    $chatOutput = $provider->chat($chatInput, $config->modelId);
    $iterator = $chatOutput->getNormalized();

    // Flush every token immediately for smooth real-time SSE delivery.
    if (method_exists($iterator, 'setMaxBufferSize')) {
      $iterator->setMaxBufferSize(1);
    }

    return $iterator;
  }

  /**
   * Streams one LLM iteration, emitting text and detecting tool calls.
   *
   * Iterates the streaming response, forwarding text chunks to the
   * browser via AgUiState. Also checks for partial tool call arguments
   * on each chunk and invokes the delta callback if configured.
   *
   * @param mixed $iterator
   *   The streaming iterator from the provider.
   * @param \Swis\AgUiServer\AgUiState $agUiState
   *   The AG-UI state manager for SSE events.
   * @param \Drupal\oe_ai_assistant\Service\LlmLoopConfig $config
   *   The loop configuration (delta callback).
   * @param string $messageId
   *   The SSE message envelope ID for this iteration.
   *
   * @return array
   *   A two-element array: [string $streamedText, array $startedToolCalls].
   */
  private function streamIteration(
    $iterator,
    AgUiState $agUiState,
    LlmLoopConfig $config,
    string $messageId,
  ): array {
    $streamedText = '';
    $messageStarted = FALSE;
    $startedToolCalls = [];

    foreach ($iterator as $chunk) {
      $text = $chunk->getText() ?? '';
      if (!empty($text)) {
        if (!$messageStarted) {
          $agUiState->startMessage('assistant', $messageId);
          $messageStarted = TRUE;
        }
        $agUiState->addMessageContent($text, $messageId);
        $streamedText .= $text;
      }

      // Detect partial tool call arguments for incremental streaming.
      $this->processPartialToolCalls(
        $iterator, $agUiState, $config, $startedToolCalls,
      );
    }

    // Close the text message SSE envelope.
    if ($messageStarted) {
      $agUiState->finishMessage($messageId);
    }

    return [$streamedText, $startedToolCalls];
  }

  /**
   * Processes partial tool call arguments from the current chunk.
   *
   * Checks if the iterator exposes partial tool call data (provider-
   * specific). If a delta callback is configured, repairs the partial
   * JSON and passes decoded arrays to the callback. Also emits
   * TOOL_CALL_START events on first detection of each tool call ID.
   *
   * @param mixed $iterator
   *   The streaming iterator (may have getPartialToolCallArguments).
   * @param \Swis\AgUiServer\AgUiState $agUiState
   *   The AG-UI state manager for SSE events.
   * @param \Drupal\oe_ai_assistant\Service\LlmLoopConfig $config
   *   The loop configuration (delta callback).
   * @param array &$startedToolCalls
   *   Tracking map of tool call IDs already started (by reference).
   */
  private function processPartialToolCalls(
    $iterator,
    AgUiState $agUiState,
    LlmLoopConfig $config,
    array &$startedToolCalls,
  ): void {
    if (!$config->onToolCallArgumentDelta
      || !method_exists($iterator, 'getPartialToolCallArguments')
    ) {
      return;
    }

    $partials = $iterator->getPartialToolCallArguments();
    if (empty($partials)) {
      return;
    }

    // Emit TOOL_CALL_START on first detection of each tool call ID.
    foreach ($partials as $tc) {
      $tcId = $tc['id'] ?? '';
      if ($tcId !== '' && !isset($startedToolCalls[$tcId])) {
        $agUiState->startToolCall($tc['name'] ?? '', NULL, $tcId);
        $startedToolCalls[$tcId] = $tc['name'] ?? '';
      }
    }

    // Repair partial JSON and pass decoded arrays to the callback.
    $decoded = [];
    foreach ($partials as $tc) {
      $args = $this->repairToolCallJson($tc['arguments_json'] ?? '');
      if ($args !== NULL) {
        $decoded[] = [
          'id' => $tc['id'] ?? '',
          'name' => $tc['name'] ?? '',
          'arguments' => $args,
        ];
      }
    }
    if (!empty($decoded)) {
      ($config->onToolCallArgumentDelta)($decoded);
    }
  }

  /**
   * Assembles tool call data from the iterator's completed tools.
   *
   * Builds two parallel structures: one for the plugin executor
   * (simple arrays) and one for the conversation history (matching
   * the provider's replay format).
   *
   * @param array $assembledTools
   *   ToolsFunctionOutput objects from the iterator's getTools().
   *
   * @return array
   *   A two-element array: [$toolCallsForExec, $toolCallsForHistory].
   */
  private function assembleToolCalls(array $assembledTools): array {
    $toolCallsForExec = [];
    $toolCallsForHistory = [];

    foreach ($assembledTools as $toolOutput) {
      $toolCallId = $toolOutput->getToolId() ?: $this->uuid->generate();
      $name = $toolOutput->getName();

      // Convert ToolsPropertyResult objects to plain key => value array.
      $rawArgs = [];
      foreach ($toolOutput->getArguments() as $arg) {
        $rawArgs[$arg->getName()] = $arg->getValue();
      }

      $toolCallsForExec[$toolCallId] = [
        'name' => $name,
        'arguments' => $rawArgs,
      ];

      // History format for provider replay (Mistral-compatible).
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

    return [$toolCallsForExec, $toolCallsForHistory];
  }

  /**
   * Emits TOOL_CALL_START events and pauses for browser rendering.
   *
   * Emits start events for tool calls not already started during
   * incremental streaming, then pauses briefly so the events reach
   * the browser before the executor emits STATE_SNAPSHOT data.
   *
   * @param \Swis\AgUiServer\AgUiState $agUiState
   *   The AG-UI state manager.
   * @param \Drupal\oe_ai_assistant\Service\LlmLoopConfig $config
   *   The loop configuration (tool executor).
   * @param array $toolCallsForExec
   *   Tool calls keyed by ID with 'name' and 'arguments'.
   * @param array $startedToolCalls
   *   Tool call IDs already started during incremental streaming.
   */
  private function emitToolCallEvents(
    AgUiState $agUiState,
    LlmLoopConfig $config,
    array $toolCallsForExec,
    array $startedToolCalls,
  ): void {
    foreach ($toolCallsForExec as $toolCallId => $toolCall) {
      if (!isset($startedToolCalls[$toolCallId])) {
        $agUiState->startToolCall(
          $toolCall['name'], NULL, $toolCallId,
        );
      }
    }

    // Brief pause so TOOL_CALL_START events reach the browser
    // before the executor emits STATE_SNAPSHOT data.
    usleep(15000);
  }

  /**
   * Wraps a raw tool call array for ChatMessage replay.
   *
   * ChatMessage::getRenderedTools() calls getOutputRenderArray() on
   * each tool call. This wrapper satisfies that interface so stored
   * plain arrays can be replayed in subsequent LLM API calls.
   *
   * @param array $data
   *   Tool call array with id, type, index, function keys.
   *
   * @return object
   *   An object with getOutputRenderArray() returning $data.
   */
  private static function wrapToolCallArray(array $data): object {
    return new class($data) {

      /**
       * Constructs the wrapper.
       *
       * @param array $data
       *   The raw tool call data.
       */
      public function __construct(private array $data) {}

      /**
       * Returns the tool call render array.
       *
       * @return array
       *   The raw tool call data.
       */
      public function getOutputRenderArray(): array {
        return $this->data;
      }

    };
  }

  /**
   * Repairs a partial tool call argument JSON string.
   *
   * Uses cortexphp/json-repair to close unclosed strings, brackets,
   * and braces in streaming JSON fragments, producing a valid array.
   *
   * @param string $json
   *   The raw (possibly incomplete) JSON argument string.
   *
   * @return array|null
   *   The decoded associative array, or NULL if unrepairable.
   */
  private function repairToolCallJson(string $json): ?array {
    if ($json === '') {
      return NULL;
    }

    try {
      $decoded = json_repair_decode($json);
    }
    catch (\Throwable) {
      return NULL;
    }

    if ($decoded instanceof \stdClass) {
      $decoded = (array) $decoded;
    }

    if (!is_array($decoded)) {
      return NULL;
    }

    return $decoded;
  }

}
