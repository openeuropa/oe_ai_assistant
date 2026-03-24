<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\PluginManager\AiShortTermMemoryPluginManager;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai\Service\FunctionCalling\StructuredExecutableFunctionCallInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\ConversationHistory;
use Drupal\oe_ai_assistant\Service\LlmLoopConfig;
use Drupal\oe_ai_assistant\Service\LlmLoopResult;
use Drupal\oe_ai_assistant\Service\LlmStreamingLoop;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abstract base class for AI assistant plugins that use LLM chat streaming.
 *
 * Sits between AiAssistantPluginBase (which provides SSE transport, action
 * dispatch, JSON body decoding, and AG-UI state management) and concrete
 * chat plugins (which supply domain-specific prompts, tools, and tool
 * executors).
 *
 * This class implements the Template Method pattern: the chat() method
 * defines the full lifecycle of an LLM chat turn, delegating domain-specific
 * steps to hooks that concrete plugins may implement or override:
 *   - buildChatContext(): extract domain context from the request body.
 *   - buildSystemPrompt(): compose the LLM system prompt.
 *   - buildTools(): declare tool definitions (default: empty set).
 *   - createToolExecutor(): build the closure that handles tool calls
 *     (default: dispatches via FunctionCall plugin manager).
 *
 * Additionally, FunctionCall plugins matching the configured group
 * (see getFunctionCallGroup()) are auto-discovered and appended to the
 * tool set before each chat turn.
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
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager, used to resolve the default chat model.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   UUID generator for run IDs, thread IDs, and message IDs.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel for the oe_ai_assistant module.
   * @param \Drupal\oe_ai_assistant\Service\ConversationHistory $conversationHistory
   *   Persists and retrieves per-thread conversation history.
   * @param \Drupal\oe_ai_assistant\Service\LlmStreamingLoop $llmLoop
   *   Runs the agentic tool-call loop against the configured LLM.
   * @param \Drupal\ai\PluginManager\AiShortTermMemoryPluginManager $shortTermMemoryManager
   *   Plugin manager for AI short-term memory plugins (e.g. LastN).
   * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallManager
   *   Plugin manager for AI FunctionCall plugins (tool auto-discovery).
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly AiProviderPluginManager $aiProvider,
    protected readonly UuidInterface $uuid,
    protected readonly LoggerInterface $logger,
    protected readonly ConversationHistory $conversationHistory,
    protected readonly LlmStreamingLoop $llmLoop,
    protected readonly AiShortTermMemoryPluginManager $shortTermMemoryManager,
    protected readonly FunctionCallPluginManager $functionCallManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Streams AI chat responses via AG-UI SSE.
   *
   * This is the template method that defines the full lifecycle of a single
   * LLM chat turn. It:
   *   1. Decodes the request body and extracts the user message.
   *   2. Delegates to abstract hooks for domain-specific context, prompt,
   *      tools, and tool executor.
   *   3. Resolves the default AI provider and model for "chat".
   *   4. Opens an SSE response and runs the LLM streaming loop inside it.
   *   5. Persists the updated conversation history on success, or emits
   *      an AG-UI error event on failure.
   *
   * Concrete plugins should NOT override this method. Instead, they
   * implement the four abstract hooks to customise behaviour.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request with an AG-UI chat message body.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   An SSE streaming response that emits AG-UI protocol events.
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
    $tools = $this->buildTools($context);
    $tools = $this->appendDiscoveredTools($tools);

    // Resolve the default provider and model for "chat" operation type.
    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
    $providerId = $defaults['provider_id'];
    $modelId = $defaults['model_id'];

    // Open the SSE response. Everything inside the callback is executed
    // after the HTTP headers have been sent, so we cannot throw exceptions
    // that bubble up to the controller -- errors must go through $state.
    return $this->createSseResponse(function () use (
      $systemPrompt, $message, $threadId, $tools,
      $providerId, $modelId, $context,
    ) {
      set_time_limit(0);

      // createAgUiState() sets $this->agUiState and returns it.
      $state = $this->createAgUiState();
      $runId = $this->uuid->generate();
      $sseThreadId = !empty($threadId) ? $threadId : $this->uuid->generate();
      $messageId = $this->uuid->generate();

      $state->startRun($sseThreadId, $runId);

      try {
        // Load conversation history for this thread.
        $history = $this->conversationHistory->load(
          $this->getHistoryCollection(), $sseThreadId
        );
        $isFirstTurn = empty($history);
        $history[] = ['role' => 'user', 'content' => $message];

        // Apply short-term memory plugin (if configured).
        // May trim history and modify systemPrompt/tools.
        [$history, $systemPrompt, $tools] =
          $this->applyShortTermMemory(
            $history, $systemPrompt, $tools, $sseThreadId,
          );

        // Delegate tool executor creation to the concrete plugin.
        $toolExecutor = $this->createToolExecutor($context, $isFirstTurn);
        // Create optional incremental streaming observer.
        $deltaObserver = $this->createToolCallDeltaObserver(
          $context, $isFirstTurn,
        );

        $config = new LlmLoopConfig(
          systemPrompt: $systemPrompt,
          conversationHistory: $history,
          tools: $tools,
          providerId: $providerId,
          modelId: $modelId,
          messageId: $messageId,
          toolExecutor: $toolExecutor,
          onToolCallArgumentDelta: $deltaObserver,
        );

        // Pass $this->agUiState (same object as $state) to the loop.
        // LlmStreamingLoop::run() uses it for SSE events.
        $loopResult = $this->llmLoop->run($this->agUiState, $config);
        $this->persistHistory($sseThreadId, $loopResult);
      }
      catch (\Exception $e) {
        $this->logger->error('Error in chat: @error', [
          '@error' => $e->getMessage(),
        ]);
        $state->errorRun($this->formatErrorForChat($e));
      }

      // Always emit RUN_FINISHED to signal the end of the SSE stream,
      // even if an error occurred, so the frontend can stop loading.
      $state->finishRun();
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
   * Extracts the user message from an AG-UI protocol request body.
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
   * Persists conversation history after a successful LLM loop.
   *
   * Appends the final assistant text (if any) to the message history
   * and saves it so subsequent chat turns have full context.
   *
   * @param string $threadId
   *   The thread ID for conversation history.
   * @param \Drupal\oe_ai_assistant\Service\LlmLoopResult $loopResult
   *   The result from the LLM streaming loop.
   */
  protected function persistHistory(string $threadId, LlmLoopResult $loopResult): void {
    $updatedHistory = $loopResult->messages;

    if (!empty($loopResult->assistantText)) {
      $updatedHistory[] = [
        'role' => 'assistant',
        'content' => $loopResult->assistantText,
      ];
    }

    $this->conversationHistory->save(
      $this->getHistoryCollection(), $threadId, $updatedHistory
    );
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
      $this->conversationHistory->delete(
        $this->getHistoryCollection(), $threadId
      );
    }

    // Always return a fresh thread ID so the frontend can start a new
    // conversation without needing to generate its own UUID.
    return ['threadId' => $this->uuid->generate()];
  }

  /**
   * Converts simple history arrays to ChatMessage objects.
   *
   * The conversation history is stored as associative arrays with 'role'
   * and 'content' keys. The AiShortTermMemory plugin API requires
   * ChatMessage objects. This method performs that conversion.
   *
   * @param array $history
   *   History entries, each with 'role' and 'content' keys.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage[]
   *   Array of ChatMessage objects.
   */
  private function convertHistoryToChatMessages(array $history): array {
    $messages = [];
    foreach ($history as $item) {
      $role = $item['role'] ?? '';
      $content = $item['content'] ?? '';
      $messages[] = new ChatMessage($role, $content);
    }
    return $messages;
  }

  /**
   * Restores original history structure after memory processing.
   *
   * The memory plugin only sees ChatMessage (role + text). Tool call
   * messages have extra keys (tool_calls_raw, tool_call_id) that must
   * survive the round-trip. Since LastN trims from the front, the
   * surviving messages correspond to the tail of the original array.
   * If the processed count is >= the original, no trimming occurred
   * and the original history is returned unchanged.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $processed
   *   ChatMessage objects returned by the memory plugin.
   * @param array $originalHistory
   *   The original history array with full metadata.
   *
   * @return array
   *   The trimmed history preserving tool call metadata.
   */
  private function rebuildHistoryFromProcessed(array $processed, array $originalHistory): array {
    $processedCount = count($processed);
    $originalCount = count($originalHistory);

    // No trimming occurred; return the original with all metadata.
    if ($processedCount >= $originalCount) {
      return $originalHistory;
    }

    // The memory plugin trimmed from the front, so take the tail
    // of the original array to preserve tool call metadata.
    $offset = $originalCount - $processedCount;
    return array_slice($originalHistory, $offset);
  }

  /**
   * Applies the configured short-term memory plugin to the chat context.
   *
   * This method encapsulates the full short-term memory flow:
   *   1. Checks whether a memory plugin is configured (returns early if not).
   *   2. Converts history arrays to ChatMessage objects for the plugin API.
   *   3. Creates the plugin instance and calls process().
   *   4. Reads back modified history, prompt, and tools.
   *   5. Rebuilds the history array preserving tool call metadata.
   *
   * @param array $history
   *   Conversation history as associative arrays.
   * @param string $systemPrompt
   *   The system prompt text.
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsInput $tools
   *   The tool definitions.
   * @param string $threadId
   *   The conversation thread ID.
   *
   * @return array
   *   A three-element array: [$history, $systemPrompt, $tools].
   */
  private function applyShortTermMemory(
    array $history,
    string $systemPrompt,
    ToolsInput $tools,
    string $threadId,
  ): array {
    $pluginId = $this->getShortTermMemoryPluginId();
    if ($pluginId === NULL) {
      return [$history, $systemPrompt, $tools];
    }

    // Convert history to ChatMessage objects for the plugin API.
    $chatMessages = $this->convertHistoryToChatMessages($history);
    $toolFunctions = $tools->getFunctions();

    // Create the memory plugin instance and run processing.
    $config = $this->getShortTermMemoryConfig();
    /** @var \Drupal\ai\Plugin\AiShortTermMemory\AiShortTermMemoryInterface $plugin */
    $plugin = $this->shortTermMemoryManager->createInstance($pluginId, $config);
    $plugin->process(
      $threadId,
      $this->getPluginId(),
      $chatMessages,
      $systemPrompt,
      $toolFunctions,
      $chatMessages,
      $systemPrompt,
      $toolFunctions,
    );

    // Read back modified values from the plugin.
    $modifiedHistory = $this->rebuildHistoryFromProcessed(
      $plugin->getChatHistory(),
      $history,
    );
    $modifiedPrompt = $plugin->getSystemPrompt();
    $modifiedTools = new ToolsInput($plugin->getTools());

    return [$modifiedHistory, $modifiedPrompt, $modifiedTools];
  }

  /**
   * Appends auto-discovered FunctionCall plugins to the tool set.
   *
   * Queries the FunctionCallPluginManager for all plugin definitions
   * whose "group" matches getFunctionCallGroup(). Each matching plugin
   * is instantiated, normalised to a ToolsFunctionInput, and appended
   * via setFunction(). If no group is configured, returns $tools
   * unchanged.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsInput $tools
   *   The tool set built by buildTools().
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsInput
   *   The tool set with any discovered plugins appended.
   */
  private function appendDiscoveredTools(ToolsInput $tools): ToolsInput {
    $group = $this->getFunctionCallGroup();
    if ($group === NULL) {
      return $tools;
    }

    $definitions = $this->functionCallManager->getDefinitions();
    foreach ($definitions as $pluginId => $definition) {
      if (($definition['group'] ?? '') !== $group) {
        continue;
      }
      /** @var \Drupal\ai\Service\FunctionCalling\FunctionCallInterface $plugin */
      $plugin = $this->functionCallManager->createInstance($pluginId);
      $tools->setFunction($plugin->normalize());
    }

    return $tools;
  }

  /**
   * Executes a FunctionCall plugin by name.
   *
   * Looks up the plugin via FunctionCallPluginManager, populates it
   * with the given arguments, and executes it if it implements
   * ExecutableFunctionCallInterface. Returns structured output if
   * available, readable output otherwise, or a fallback confirmation.
   *
   * @param string $name
   *   The function name as declared by the FunctionCall plugin.
   * @param array $args
   *   The arguments from the LLM tool call.
   *
   * @return array|string
   *   The execution result: structured array, readable string wrapper,
   *   or an error array if the function is unknown.
   */
  protected function executeFunctionCallPlugin(string $name, array $args): array|string {
    if (!$this->functionCallManager->functionExists($name)) {
      return ['error' => "Unknown tool: $name"];
    }

    /** @var \Drupal\ai\Service\FunctionCalling\FunctionCallInterface $plugin */
    $plugin = $this->functionCallManager->getFunctionCallFromFunctionName($name);
    $input = $plugin->normalize();
    $output = new ToolsFunctionOutput($input, '', $args);
    $plugin->populateValues($output);

    // Execute the plugin if it supports execution.
    if ($plugin instanceof ExecutableFunctionCallInterface) {
      $plugin->execute();
    }

    // Return structured output if available, readable output if not.
    if ($plugin instanceof StructuredExecutableFunctionCallInterface) {
      return $plugin->getStructuredOutput();
    }
    if ($plugin instanceof ExecutableFunctionCallInterface) {
      return ['result' => $plugin->getReadableOutput()];
    }

    return ['result' => 'executed'];
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
   * Default returns an empty tool set. Concrete plugins override to
   * add manual tool definitions. Auto-discovered FunctionCall plugins
   * are appended separately by appendDiscoveredTools().
   *
   * @param array $context
   *   The context array returned by buildChatContext().
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsInput
   *   The tool definitions.
   */
  protected function buildTools(array $context): ToolsInput {
    return new ToolsInput();
  }

  /**
   * Creates the tool executor closure for this chat turn.
   *
   * The default implementation dispatches all tool calls through
   * executeFunctionCallPlugin(), which handles auto-discovered
   * FunctionCall plugins. Concrete plugins that define manual tools
   * should override this to add their own dispatch logic.
   *
   * The returned closure has the signature:
   *   fn(array $toolCalls): array
   * where $toolCalls maps tool call ID to
   * ['name' => string, 'arguments' => array] and the return value
   * is a list of tool result messages for the conversation history.
   *
   * @param array $context
   *   The context array returned by buildChatContext().
   * @param bool $isFirstTurn
   *   TRUE if the conversation history was empty before this turn.
   *
   * @return \Closure
   *   The tool executor closure.
   */
  protected function createToolExecutor(
    array $context,
    bool $isFirstTurn,
  ): \Closure {
    return function (array $toolCalls): array {
      $results = [];
      foreach ($toolCalls as $toolCallId => $toolCall) {
        $name = $toolCall['name'] ?? '';
        $args = $toolCall['arguments'] ?? [];
        $result = $this->executeFunctionCallPlugin($name, $args);
        $results[] = [
          'role' => 'tool',
          'content' => Json::encode($result),
          'tool_call_id' => $toolCallId,
        ];
      }
      return $results;
    };
  }

  /**
   * Creates an observer for incremental tool call argument streaming.
   *
   * Called alongside createToolExecutor() during chat setup. The
   * returned closure is invoked on every streaming chunk that carries
   * partial tool call arguments, enabling plugins to stream field
   * values to the frontend before the tool call completes.
   *
   * The default implementation returns NULL, which disables
   * incremental streaming. Concrete plugins that support real-time
   * field streaming (e.g. DraftingPlugin) override this method.
   *
   * @param array $context
   *   The context array returned by buildChatContext().
   * @param bool $isFirstTurn
   *   TRUE if the conversation history was empty before this turn.
   *
   * @return \Closure|null
   *   A closure with signature fn(array $partialToolCalls): void,
   *   or NULL to disable incremental streaming.
   */
  protected function createToolCallDeltaObserver(
    array $context,
    bool $isFirstTurn,
  ): ?\Closure {
    return NULL;
  }

}
