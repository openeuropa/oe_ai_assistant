<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\ai\PluginManager\AiShortTermMemoryPluginManager;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Processor\ShortTermMemoryInputProcessor;
use Drupal\oe_ai_assistant\Service\AgentFactory;
use Drupal\oe_ai_assistant\Store\DrupalTempMessageStore;
use Drupal\oe_ai_assistant\Streaming\DataStreamWriter;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use function Cortex\JsonRepair\json_repair_decode;

/**
 * Abstract base class for AI assistant plugins that use LLM chat streaming.
 *
 * Sits between AiAssistantPluginBase (which provides SSE transport, action
 * dispatch, JSON body decoding, and DataStreamWriter management) and concrete
 * chat plugins (which supply domain-specific prompts, tools, and tool
 * executors).
 *
 * This class implements the Template Method pattern: the chat() method
 * defines the full lifecycle of an LLM chat turn, delegating domain-specific
 * steps to hooks that concrete plugins may implement or override:
 *   - buildChatContext(): extract domain context from the request body.
 *   - buildSystemPrompt(): compose the LLM system prompt.
 *   - buildTools(): declare tool definitions for the LLM.
 *   - createToolCallDeltaObserver(): create a callback for incremental
 *     tool call streaming (return NULL to disable).
 *
 * Uses Symfony AI Agent for LLM orchestration. Tool execution is handled
 * by the Agent's Toolbox via CompositeToolbox, which bridges Symfony AI
 * tools with Drupal's FunctionCall plugin system. Conversation history
 * is persisted via DrupalTempMessageStore using Drupal's PrivateTempStore.
 *
 * Concrete plugins do NOT need to implement create(); that responsibility
 * stays with the final plugin class, which knows its own service
 * dependencies.
 */
abstract class ChatPluginBase extends AiAssistantPluginBase {

  /**
   * Constructs a ChatPluginBase.
   *
   * Concrete plugins call parent::__construct() from their own constructor
   * and add any additional services they need.
   *
   * @param array $configuration
   *   Plugin configuration array from the plugin manager.
   * @param string $plugin_id
   *   The plugin ID as declared in the plugin attribute.
   * @param mixed $plugin_definition
   *   The plugin definition as resolved by the plugin manager.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   UUID generator for run IDs, thread IDs, and message IDs.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel for the oe_ai_assistant module.
   * @param \Drupal\oe_ai_assistant\Service\AgentFactory $agentFactory
   *   Factory for creating configured Symfony AI Agent instances.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   Factory for per-user temp stores used for conversation history.
   * @param \Drupal\ai\PluginManager\AiShortTermMemoryPluginManager $shortTermMemoryManager
   *   Plugin manager for AI short-term memory plugins (e.g. LastN).
   * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallManager
   *   Plugin manager for AI FunctionCall plugins (tool auto-discovery).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly UuidInterface $uuid,
    protected readonly LoggerInterface $logger,
    protected readonly AgentFactory $agentFactory,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
    protected readonly AiShortTermMemoryPluginManager $shortTermMemoryManager,
    protected readonly FunctionCallPluginManager $functionCallManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Streams AI chat responses via SSE using the Data Stream Protocol.
   *
   * This is the template method that defines the full lifecycle of a single
   * LLM chat turn. It:
   *   1. Decodes the request body and extracts the user message.
   *   2. Delegates to abstract hooks for domain-specific context, prompt,
   *      tools, and tool executor.
   *   3. Resolves the default AI provider and model for "chat".
   *   4. Opens an SSE response and runs the LLM streaming loop inside it.
   *   5. Persists the updated conversation history on success, or emits
   *      an error event on failure.
   *
   * Concrete plugins should NOT override this method. Instead, they
   * implement the four abstract hooks to customise behaviour.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request with a chat message body.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   An SSE streaming response that emits Data Stream Protocol events.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   If the request body contains no user message.
   */
  public function chat(Request $request): Response {
    $body = $this->decodeJsonBody($request);

    $message = $this->extractUserMessage($body);
    $threadId = $body['threadId'] ?? '';

    if (empty($message)) {
      throw new ActionException('invalid_request', 'Message is required.', 400);
    }

    // Delegate to subclass hooks before opening the SSE response so that
    // any errors (e.g. schema extraction failures) surface as normal HTTP
    // error responses rather than mid-stream.
    $context = $this->buildChatContext($body, $message);
    $systemPrompt = $this->buildSystemPrompt($context);
    $toolDefinitions = $this->buildTools($context);

    // Open the SSE response. Everything inside the callback is executed
    // after the HTTP headers have been sent, so we cannot throw exceptions
    // that bubble up to the controller -- errors must go through the writer.
    return $this->createSseResponse(function () use (
      $systemPrompt, $message, $threadId, $toolDefinitions, $context,
    ) {
      set_time_limit(0);

      // createWriter() sets $this->writer and returns it.
      $writer = $this->createWriter();
      $messageId = $this->uuid->generate();
      $writer->emit('start', ['messageId' => $messageId]);

      try {
        $sseThreadId = !empty($threadId) ? $threadId : $this->uuid->generate();

        // Load conversation history from the store.
        $store = new DrupalTempMessageStore(
          $this->tempStoreFactory,
          $this->getHistoryCollection(),
          $sseThreadId,
        );
        $bag = $store->load();
        $isFirstTurn = count($bag->getMessages()) === 0;

        // Add the user message and system prompt.
        $bag->add(Message::ofUser($message));
        $bag = $bag->withSystemMessage(
          Message::forSystem($systemPrompt),
        );

        // Build the event dispatcher for tool call lifecycle events.
        $eventDispatcher = new EventDispatcher();

        // Register event emitter for tool call results.
        $eventDispatcher->addListener(
          ToolCallSucceeded::class,
          function (ToolCallSucceeded $event) use ($writer): void {
            $toolCall = $event->getResult()->getToolCall();
            $result = $event->getResult()->getResult();
            $writer->emit('tool-input-available', [
              'toolCallId' => $toolCall->getId(),
              'toolName' => $toolCall->getName(),
              'input' => $toolCall->getArguments(),
            ]);
            $writer->emit('tool-output-available', [
              'toolCallId' => $toolCall->getId(),
              'output' => is_string($result)
                ? (json_decode($result, TRUE) ?? $result)
                : $result,
            ]);
          },
        );

        // Allow concrete plugins to register additional event
        // listeners (e.g. for data event emission on tool
        // completion). This hook fires before the Agent is created
        // so listeners are in place for the entire streaming session.
        $this->registerToolEventListeners(
          $eventDispatcher, $context, $isFirstTurn,
        );

        // Build input processors.
        $inputProcessors = $this->buildInputProcessors(
          $sseThreadId, $isFirstTurn,
        );

        // Extract tool objects, metadata, and FunctionCall group.
        $toolObjects = [];
        $toolMetadata = [];
        foreach ($toolDefinitions as $def) {
          $toolObjects[] = $def['instance'];
          $toolMetadata[] = $def['metadata'];
        }
        $functionCallGroup = $this->getFunctionCallGroup();

        // Create the Agent via the factory.
        [$agent, $modelId] = $this->agentFactory->createAgent(
          $toolObjects,
          $toolMetadata,
          $functionCallGroup,
          $inputProcessors,
          $eventDispatcher,
        );

        // Create the delta observer for incremental tool call streaming.
        $deltaObserver = $this->createToolCallDeltaObserver(
          $context, $isFirstTurn,
        );

        // Stream deltas from the agent call.
        $writer->emit('start-step');
        $result = $agent->call($bag, ['stream' => TRUE]);

        $accumulatedText = $this->streamDeltas(
          $result, $writer, $deltaObserver,
        );

        $writer->emit('finish-step');

        // Persist conversation: add final assistant message and save
        // without the system message (rebuilt fresh each request).
        if (!empty($accumulatedText)) {
          $bag->add(Message::ofAssistant($accumulatedText));
        }
        $store->save($bag->withoutSystemMessage());

        $writer->emit('finish', ['finishReason' => 'stop']);
      }
      catch (\Exception $e) {
        $this->logger->error('Error in chat: @error', [
          '@error' => $e->getMessage(),
        ]);
        $writer->emit('error', [
          'errorText' => $this->formatErrorForChat($e),
        ]);
      }

      $writer->done();
    });
  }

  /**
   * Resets the conversation thread.
   *
   * Standard action inherited by all chat plugins. Delegates to
   * resetThread(), which deletes stored history and returns a fresh
   * thread ID for the frontend.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request. Body must include a "threadId" key.
   *
   * @return array<string, string>
   *   An array with a single "threadId" key containing the new thread ID.
   */
  public function reset(Request $request): array {
    return $this->resetThread($request);
  }

  /**
   * Extracts the user message from the request body.
   *
   * The frontend may send a top-level "message" string (simple format)
   * or a "messages" array in the OpenAI conversation format. We support
   * both and extract the last user-role message in the array case.
   *
   * @param array $body
   *   The decoded JSON request body.
   *
   * @return string
   *   The extracted user message text, or empty string if none found.
   */
  protected function extractUserMessage(array $body): string {
    $message = $body['message'] ?? '';
    if (!empty($message)) {
      return $message;
    }

    if (empty($body['messages'])) {
      return '';
    }

    // Filter to user-role messages and take the last one.
    $userMessages = array_filter(
      $body['messages'],
      fn($m) => ($m['role'] ?? '') === 'user',
    );
    $lastUserMessage = end($userMessages);

    // Content may be a plain string or an array of content parts
    // (e.g. [{ "type": "text", "text": "..." }]).
    if (is_array($lastUserMessage['content'] ?? '')) {
      return implode('', array_map(
        fn($p) => $p['text'] ?? '',
        $lastUserMessage['content'],
      ));
    }

    return $lastUserMessage['content'] ?? '';
  }

  /**
   * Formats an exception into a user-friendly chat error message.
   *
   * AI provider errors (e.g. from Mistral) often contain HTTP status
   * codes or technical details that are unhelpful to editors. This
   * method detects common provider error patterns and returns a
   * message suitable for display in the chat UI.
   *
   * @param \Exception $e
   *   The caught exception.
   *
   * @return string
   *   A user-facing error message.
   */
  protected function formatErrorForChat(\Exception $e): string {
    $message = $e->getMessage();

    // Detect HTTP status codes commonly returned by AI provider APIs.
    // These surface as exception messages from the Guzzle HTTP client
    // or from the provider plugin itself.
    if (preg_match('/\b401\b|unauthorized/i', $message)) {
      return 'The AI service rejected the API key. Please check the provider configuration.';
    }
    if (preg_match('/\b403\b|forbidden/i', $message)) {
      return 'The AI service denied access. Please check the provider permissions.';
    }
    if (preg_match('/\b429\b|rate.?limit|too many requests/i', $message)) {
      return 'The AI service is temporarily overloaded. Please try again in a moment.';
    }
    if (preg_match('/\b5\d{2}\b|server error|internal error|service unavailable/i', $message)) {
      return 'The AI service is temporarily unavailable. Please try again later.';
    }
    if (preg_match('/timeout|timed? out/i', $message)) {
      return 'The AI service did not respond in time. Please try again.';
    }
    if (preg_match('/connection refused|could not resolve|network/i', $message)) {
      return 'Could not reach the AI service. Please check your network connection.';
    }

    // Fall back to the original message for unrecognised errors.
    return $message;
  }

  /**
   * Returns the conversation history collection key for this plugin.
   *
   * Uses the plugin ID to namespace history storage, so each plugin's
   * conversation threads are isolated from one another.
   *
   * @return string
   *   The collection key, e.g. "oe_ai_drafting".
   */
  protected function getHistoryCollection(): string {
    return 'oe_ai_' . $this->getPluginId();
  }

  /**
   * Returns the short-term memory plugin ID to apply before each LLM turn.
   *
   * Override in concrete plugins to enable short-term memory processing
   * (e.g. "last_n" to trim history to the N most recent messages).
   * Returns NULL by default, which disables short-term memory.
   *
   * @return string|null
   *   The plugin ID, or NULL to skip memory processing.
   */
  protected function getShortTermMemoryPluginId(): ?string {
    return 'last_n';
  }

  /**
   * Returns configuration for the short-term memory plugin.
   *
   * Override in concrete plugins to pass plugin-specific settings
   * (e.g. ['last_n' => 10] for the LastN plugin). Only called when
   * getShortTermMemoryPluginId() returns a non-null value.
   *
   * @return array
   *   Configuration array passed to the plugin's createInstance().
   */
  protected function getShortTermMemoryConfig(): array {
    return ['max_messages' => 20];
  }

  /**
   * Returns the FunctionCall plugin group to auto-discover.
   *
   * Override in concrete plugins to enable automatic discovery of
   * FunctionCall plugins that belong to a specific group. All plugins
   * whose @FunctionCall annotation has a matching "group" property
   * will be appended to the tool set before each chat turn.
   *
   * Returns NULL by default, which disables auto-discovery.
   *
   * @return string|null
   *   The group name, or NULL to skip auto-discovery.
   */
  protected function getFunctionCallGroup(): ?string {
    return NULL;
  }

  /**
   * Resets the conversation thread.
   *
   * Deletes the stored history for the given thread ID and returns a fresh
   * thread ID that the frontend should use for subsequent chat requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request. Body must include a "threadId" key.
   *
   * @return array<string, string>
   *   An array with a single "threadId" key containing the new thread ID.
   */
  protected function resetThread(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $threadId = $body['threadId'] ?? '';

    // Only attempt deletion if a thread ID was provided; a missing or
    // empty thread ID means there is nothing to clear.
    if (!empty($threadId)) {
      $store = new DrupalTempMessageStore(
        $this->tempStoreFactory,
        $this->getHistoryCollection(),
        $threadId,
      );
      $store->drop();
    }

    // Always return a fresh thread ID so the frontend can start a new
    // conversation without needing to generate its own UUID.
    return ['threadId' => $this->uuid->generate()];
  }



  /**
   * Parses domain-specific context from the request body.
   *
   * Called before opening the SSE response. Any errors thrown here
   * surface as normal HTTP error responses (not mid-stream).
   *
   * @param array $body
   *   The decoded JSON request body.
   * @param string $message
   *   The extracted user message text.
   *
   * @return array
   *   Domain-specific context consumed by the other abstract hooks.
   */
  abstract protected function buildChatContext(
    array $body,
    string $message,
  ): array;

  /**
   * Builds the system prompt for the LLM.
   *
   * @param array $context
   *   The context array returned by buildChatContext().
   *
   * @return string
   *   The system prompt text.
   */
  abstract protected function buildSystemPrompt(array $context): string;

  /**
   * Builds the tool definitions exposed to the LLM.
   *
   * Concrete plugins return their tool definitions here as an array
   * of associative arrays, each with 'instance' (the callable tool
   * object) and 'metadata' (a Symfony AI Tool descriptor). FunctionCall
   * plugins are auto-discovered separately by the AgentFactory via
   * CompositeToolbox. Return an empty array if the plugin does not
   * define any tools.
   *
   * @param array $context
   *   The context array returned by buildChatContext().
   *
   * @return array<array{instance: object, metadata: \Symfony\AI\Platform\Tool\Tool}>
   *   The tool definitions.
   */
  abstract protected function buildTools(array $context): array;

  /**
   * Creates an observer for incremental tool call argument streaming.
   *
   * Called on each streaming delta that carries partial tool call
   * arguments. The base class repairs the partial JSON and passes
   * decoded arrays to the callback. Return NULL to disable incremental
   * streaming for this plugin.
   *
   * @param array $context
   *   The context array from buildChatContext().
   * @param bool $isFirstTurn
   *   TRUE if the conversation history was empty.
   *
   * @return \Closure|null
   *   Callback with signature fn(string $toolName, array $decoded): void.
   *   Return NULL to disable incremental streaming.
   */
  abstract protected function createToolCallDeltaObserver(
    array $context,
    bool $isFirstTurn,
  ): ?\Closure;

  /**
   * Registers plugin-specific event listeners on the tool dispatcher.
   *
   * Called before the Agent is created, so listeners are in place for
   * the entire streaming session. Concrete plugins override this to
   * hook into Symfony AI tool lifecycle events (e.g. ToolCallSucceeded)
   * for side effects like emitting data events via the DataStreamWriter.
   *
   * The base implementation is a no-op.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcher $eventDispatcher
   *   The event dispatcher shared with the Toolbox and AgentProcessor.
   * @param array $context
   *   The context array from buildChatContext().
   * @param bool $isFirstTurn
   *   TRUE if the conversation history was empty before this turn.
   */
  protected function registerToolEventListeners(
    EventDispatcher $eventDispatcher,
    array $context,
    bool $isFirstTurn,
  ): void {
    // No-op by default. Concrete plugins override to register
    // event listeners for tool call side effects.
  }

  /**
   * Iterates streaming deltas and emits Data Stream Protocol events.
   *
   * Processes each delta from the Symfony AI StreamResult: TextDelta
   * chunks are forwarded as text-delta events, ToolCallStart deltas
   * trigger tool-input-start events, and ToolInputDelta chunks are
   * emitted as tool-input-delta events and passed to the plugin's
   * delta observer for incremental field streaming.
   *
   * @param mixed $result
   *   The StreamResult from Agent::call() with streaming enabled.
   * @param \Drupal\oe_ai_assistant\Streaming\DataStreamWriter $writer
   *   The DataStreamWriter for emitting SSE events.
   * @param \Closure|null $deltaObserver
   *   Optional callback for incremental tool call argument streaming.
   *
   * @return string
   *   The accumulated assistant text from all TextDelta chunks.
   */
  private function streamDeltas(
    $result,
    DataStreamWriter $writer,
    ?\Closure $deltaObserver,
  ): string {
    $accumulatedText = '';
    $textStarted = FALSE;
    $textId = $this->uuid->generate();

    foreach ($result->getContent() as $delta) {
      // The AgentProcessor's StreamListener may replace a
      // ToolCallComplete delta with a string (the final text from
      // the re-invoked agent). Handle plain strings as text.
      if (is_string($delta)) {
        if (!$textStarted) {
          $writer->emit('text-start', ['id' => $textId]);
          $textStarted = TRUE;
        }
        $writer->emit('text-delta', [
          'id' => $textId,
          'delta' => $delta,
        ]);
        $accumulatedText .= $delta;
        continue;
      }

      if ($delta instanceof TextDelta) {
        if (!$textStarted) {
          $writer->emit('text-start', ['id' => $textId]);
          $textStarted = TRUE;
        }
        $writer->emit('text-delta', [
          'id' => $textId,
          'delta' => $delta->getText(),
        ]);
        $accumulatedText .= $delta->getText();
      }
      elseif ($delta instanceof ToolCallStart) {
        if ($textStarted) {
          $writer->emit('text-end', ['id' => $textId]);
          $textStarted = FALSE;
        }
        $writer->emit('tool-input-start', [
          'toolCallId' => $delta->getId(),
          'toolName' => $delta->getName(),
        ]);
      }
      elseif ($delta instanceof ToolInputDelta) {
        $writer->emit('tool-input-delta', [
          'toolCallId' => $delta->getId(),
          'inputTextDelta' => $delta->getPartialJson(),
        ]);
        if ($deltaObserver !== NULL) {
          $decoded = $this->repairPartialJson(
            $delta->getPartialJson(),
          );
          if ($decoded !== NULL) {
            $deltaObserver($delta->getName(), $decoded);
          }
        }
      }
    }

    if ($textStarted) {
      $writer->emit('text-end', ['id' => $textId]);
    }

    return $accumulatedText;
  }

  /**
   * Attempts to repair and decode partial JSON from streaming deltas.
   *
   * Tool call arguments arrive as incomplete JSON fragments during
   * streaming. This method uses the cortexphp/json-repair library
   * to attempt decoding, returning NULL for empty or unrecoverable
   * fragments rather than throwing.
   *
   * @param string $json
   *   The partial JSON string to repair.
   *
   * @return array|null
   *   The decoded array, or NULL if repair fails or input is empty.
   */
  private function repairPartialJson(string $json): ?array {
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
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Builds input processors for the Symfony AI Agent pipeline.
   *
   * Creates a ShortTermMemoryInputProcessor if a memory plugin is
   * configured (via getShortTermMemoryPluginId()), which trims
   * conversation history before each LLM call.
   *
   * @param string $threadId
   *   The conversation thread ID.
   * @param bool $isFirstTurn
   *   TRUE if this is the first message in the thread.
   *
   * @return \Symfony\AI\Agent\InputProcessorInterface[]
   *   Array of input processors for the Agent.
   */
  private function buildInputProcessors(
    string $threadId,
    bool $isFirstTurn,
  ): array {
    $processors = [];
    $memoryPluginId = $this->getShortTermMemoryPluginId();
    if ($memoryPluginId !== NULL) {
      $processors[] = new ShortTermMemoryInputProcessor(
        $this->shortTermMemoryManager,
        $memoryPluginId,
        $this->getShortTermMemoryConfig(),
        $threadId,
        $this->getPluginId(),
      );
    }
    return $processors;
  }

}
