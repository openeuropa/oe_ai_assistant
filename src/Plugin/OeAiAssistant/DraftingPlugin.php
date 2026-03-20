<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\OeAiAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\oe_ai_assistant\Annotation\AiAssistantPlugin;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Service\DraftFieldMapper;
use Drupal\oe_ai_assistant\Service\FormSchemaExtractor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drafting plugin: AI-powered content drafting via direct LLM streaming.
 *
 * Uses drupal/ai core directly (not ai_assistant_api) for full control over
 * streaming and tool call handling. Manages its own conversation history
 * via PrivateTempStore. SSE events are emitted through the swisnl/ag-ui-server
 * AgUiState manager with adaptive delta buffering for text streaming.
 */
#[AiAssistantPlugin(
  id: 'drafting',
  label: 'Drafting',
  description: 'AI-powered content drafting with AG-UI streaming.',
)]
class DraftingPlugin extends AiAssistantPluginBase {

  /**
   * Maximum iterations for the tool-call loop.
   */
  private const MAX_ITERATIONS = 10;

  /**
   * Number of recent message pairs to include as context.
   */
  private const HISTORY_LENGTH = 10;

  /**
   * Field names that should be streamed progressively.
   *
   * When empty, all fields are streamed (first draft). When set,
   * only listed fields are streamed word-by-word; the rest are
   * sent in one snapshot. Parsed from the user message's
   * [fields:name1,name2] tag.
   *
   * @var string[]
   */
  private array $fieldsToStream = [];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DraftFieldMapper $fieldMapper,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly AiProviderPluginManager $aiProvider,
    protected readonly UuidInterface $uuid,
    protected readonly LoggerInterface $logger,
    protected readonly FormSchemaExtractor $formSchemaExtractor,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get(DraftFieldMapper::class),
      $container->get('current_user'),
      $container->get('ai.provider'),
      $container->get('uuid'),
      $container->get('logger.factory')->get('oe_ai_assistant'),
      $container->get(FormSchemaExtractor::class),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getActionMap(): array {
    return [
      'chat' => $this->chat(...),
      'reset' => $this->reset(...),
      'save' => $this->save(...),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestSchemas(): array {
    return [
      'reset' => 'DraftingResetRequest',
      'save' => 'DraftingSaveRequest',
    ];
  }

  /**
   * Streams AI chat responses via AG-UI SSE.
   */
  public function chat(Request $request): Response {
    $body = $this->decodeJsonBody($request);

    // Extract user message from AG-UI protocol format.
    $message = $body['message'] ?? '';
    if (empty($message) && !empty($body['messages'])) {
      $userMessages = array_filter(
        $body['messages'],
        fn($m) => ($m['role'] ?? '') === 'user',
      );
      $lastUserMessage = end($userMessages);
      $message = is_array($lastUserMessage['content'] ?? '')
        ? implode('', array_map(fn($p) => $p['text'] ?? '', $lastUserMessage['content']))
        : ($lastUserMessage['content'] ?? '');
    }

    $threadId = $body['threadId'] ?? '';
    $forwardedProps = $body['forwardedProps'] ?? [];
    $entityTypeId = $forwardedProps['entityTypeId'] ?? $body['entityTypeId'] ?? 'node';
    $bundle = $forwardedProps['bundle'] ?? $body['bundle'] ?? '';

    if (empty($message)) {
      throw new ActionException('invalid_request', 'Message is required.', 400);
    }

    // Build system prompt with content type schema.
    $systemPrompt = $this->buildSystemPrompt($entityTypeId, $bundle);

    // Build tool definitions.
    $tools = $this->buildTools();

    // Get provider and model.
    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
    $providerId = $defaults['provider_id'];
    $modelId = $defaults['model_id'];

    // Build a flat field index for progressive snapshot streaming.
    $fieldIndex = $this->buildFieldIndex($entityTypeId, $bundle);

    // Parse [fields:name1,name2] tag from the user message to know
    // which fields to stream progressively on regeneration. When
    // absent, all fields are streamed (first draft).
    if (preg_match('/\[fields:([^\]]+)\]/', $message, $matches)) {
      $this->fieldsToStream = explode(',', $matches[1]);
    }

    return $this->createStreamResponse(
      $systemPrompt,
      $message,
      $threadId,
      $tools,
      $providerId,
      $modelId,
      $bundle,
      $fieldIndex,
    );
  }

  /**
   * Resets the conversation thread.
   */
  public function reset(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $threadId = $body['threadId'] ?? '';

    if (!empty($threadId)) {
      $store = $this->tempStoreFactory->get('oe_ai_drafting');
      $store->delete($threadId);
    }

    return ['threadId' => $this->uuid->generate()];
  }

  /**
   * Creates a Drupal node from an approved draft.
   */
  public function save(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $bundle = $body['bundle'] ?? '';
    $fields = $body['fields'] ?? [];

    if (!$this->currentUser->hasPermission("create $bundle content")) {
      throw new ActionException(
        'forbidden',
        sprintf('You do not have permission to create %s content.', $bundle),
        403,
      );
    }

    try {
      $node = $this->fieldMapper->createNode($bundle, $fields);
    }
    catch (\InvalidArgumentException $e) {
      throw new ActionException('invalid_bundle', $e->getMessage(), 400);
    }

    return [
      'nodeId' => (string) $node->id(),
      'previewUrl' => $this->fieldMapper->getPreviewUrl($node),
    ];
  }

  /**
   * Builds the system prompt with the content type schema appended.
   */
  private function buildSystemPrompt(string $entityTypeId, string $bundle): string {
    $prompt = <<<'PROMPT'
You are a content drafting assistant for a CMS editorial workflow.

You can have normal conversations with the editor. Answer questions, discuss
ideas, and help them plan their content. Only use the draft_content tool when
the editor explicitly asks you to generate or draft content.

When the editor asks you to draft or generate content:
- Use the draft_content tool to return structured field values matching the
  content type schema provided below.
- Always return the COMPLETE set of fields in every tool call, not just the
  ones you changed. The frontend replaces the entire draft on each call.
- When the editor asks to regenerate specific fields, return the full draft
  with those fields updated and all other fields unchanged.
- Do NOT produce values for entity reference fields (media, taxonomies, etc.).
  These are handled separately by the editor.
- For formatted text fields, produce clean HTML appropriate for the field.
- Match the language and tone the editor requests.

When the editor asks a question, makes a comment, or has a conversation that
does not involve generating content, respond normally in plain text. Do NOT
call the draft_content tool for conversational responses.
PROMPT;

    if (!empty($bundle)) {
      try {
        $schema = $this->formSchemaExtractor->extract($entityTypeId, $bundle);
        $prompt .= "\n\nContent type schema:\n" . Json::encode($schema);
      }
      catch (\Exception $e) {
        $this->logger->warning('Could not load schema for @type/@bundle: @error', [
          '@type' => $entityTypeId,
          '@bundle' => $bundle,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $prompt;
  }

  /**
   * Builds a flat field index from the content type schema.
   *
   * Flattens the grouped schema structure into a lookup keyed by field
   * machine name. Used by streamFieldSnapshots() to determine which
   * fields should be streamed word-by-word vs sent whole.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The content type bundle.
   *
   * @return array
   *   Field definitions keyed by machine name.
   */
  private function buildFieldIndex(string $entityTypeId, string $bundle): array {
    if (empty($bundle)) {
      return [];
    }
    try {
      $schema = $this->formSchemaExtractor->extract($entityTypeId, $bundle);
      $index = [];
      foreach ($schema['groups'] ?? [] as $group) {
        foreach ($group['fields'] ?? [] as $field) {
          $index[$field['name']] = $field;
        }
      }
      return $index;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Builds tool definitions for the LLM.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsInput
   *   The tools input.
   */
  private function buildTools(): ToolsInput {
    // draft_content tool: produces structured field values.
    $draftTool = new ToolsFunctionInput();
    $draftTool->setName('draft_content');
    $draftTool->setDescription(
      'Produce a complete set of field values for the content type. '
      . 'Field names and value shapes must match the content type schema '
      . 'provided in the system prompt. Always return ALL fields.'
    );
    $fieldsParam = new ToolsPropertyInput();
    $fieldsParam->setName('fields');
    $fieldsParam->setType('object');
    $fieldsParam->setDescription('Complete field values keyed by field machine name.');
    $fieldsParam->setRequired(TRUE);
    $draftTool->setProperties([$fieldsParam]);

    return new ToolsInput([$draftTool]);
  }

  /**
   * Creates the SSE streaming response.
   *
   * Uses AiStreamedResponse (via the base class) which handles output
   * buffer clearing and streaming headers automatically. The AG-UI state
   * manager handles event construction and delta buffering.
   */
  private function createStreamResponse(
    string $systemPrompt,
    string $userMessage,
    string $threadId,
    ToolsInput $tools,
    string $providerId,
    string $modelId,
    string $bundle,
    array $fieldIndex,
  ): Response {
    return $this->createSseResponse(function () use (
      $systemPrompt, $userMessage, $threadId, $tools, $providerId, $modelId, $bundle, $fieldIndex,
    ) {
      set_time_limit(0);

      // Initialize the AG-UI state manager with delta buffering.
      $state = $this->createAgUiState();

      $runId = $this->uuid->generate();
      $sseThreadId = !empty($threadId) ? $threadId : $this->uuid->generate();
      $messageId = $this->uuid->generate();

      $state->startRun($sseThreadId, $runId);

      try {
        // Load conversation history.
        $history = $this->loadHistory($sseThreadId);

        // Add the new user message to history.
        $history[] = ['role' => 'user', 'content' => $userMessage];

        // Run the LLM with tool loop.
        $assistantText = $this->runLlmLoop(
          $systemPrompt,
          $history,
          $tools,
          $providerId,
          $modelId,
          $messageId,
          $bundle,
          $fieldIndex,
        );

        // Save updated history.
        if (!empty($assistantText)) {
          $history[] = ['role' => 'assistant', 'content' => $assistantText];
        }
        $this->saveHistory($sseThreadId, $history);
      }
      catch (\Exception $e) {
        $this->logger->error('Error in drafting chat: @error', [
          '@error' => $e->getMessage(),
        ]);
        $state->errorRun($e->getMessage());
      }

      $state->finishRun();
    });
  }

  /**
   * Runs the LLM call with tool loop and streams SSE events.
   *
   * Uses the drupal/ai provider plugin system for streaming chat responses
   * with tool call support. The StreamedChatMessageIterator assembles
   * tool call deltas automatically via getTools(). Text message events
   * are emitted through AgUiState which applies delta buffering.
   *
   * @return string
   *   The final assistant text message.
   */
  private function runLlmLoop(
    string $systemPrompt,
    array $conversationHistory,
    ToolsInput $tools,
    string $providerId,
    string $modelId,
    string $messageId,
    string $bundle,
    array $fieldIndex,
  ): string {
    $messages = $conversationHistory;
    $fullMessage = '';

    for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
      // Build the ChatInput from conversation history. Tool result
      // messages need setToolsId() and assistant messages with tool
      // calls need setTools() for the provider to handle them correctly.
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
          // Set tool calls on the assistant message so the provider can
          // include them in the next API call. Wrap each raw array so
          // ChatMessage::getRenderedTools() returns the correct format.
          $chatMsg->setTools(array_map(
            [self::class, 'wrapToolCallArray'],
            $msg['tool_calls_raw'],
          ));
        }
        $chatMessages[] = $chatMsg;
      }
      $chatInput = new ChatInput($chatMessages);
      $chatInput->setSystemPrompt($systemPrompt);
      $chatInput->setStreamedOutput(TRUE);
      $chatInput->setChatTools($tools);

      // Get the AI provider instance and perform a streamed chat call.
      $provider = $this->aiProvider->createInstance($providerId);
      $provider->setConfiguration(['model_id' => $modelId]);

      $streamedText = '';
      $messageStarted = FALSE;

      // Call chat() with streaming enabled. The provider returns a
      // ChatOutput whose getNormalized() is a StreamedChatMessageIterator.
      $chatOutput = $provider->chat($chatInput, $modelId);
      $iterator = $chatOutput->getNormalized();

      // Flush every token immediately for smooth real-time SSE delivery.
      // The default 100-char buffer causes chunks to arrive in bursts.
      if (method_exists($iterator, 'setMaxBufferSize')) {
        $iterator->setMaxBufferSize(1);
      }

      // Stream text chunks to the client via AgUiState. Delta buffering
      // batches tokens before sending to reduce SSE event count while
      // maintaining smooth streaming (flushes at 100 chars or 150ms).
      foreach ($iterator as $chunk) {
        $text = $chunk->getText() ?? '';
        if (!empty($text)) {
          if (!$messageStarted) {
            $this->agUiState->startMessage('assistant', $messageId);
            $messageStarted = TRUE;
          }
          $this->agUiState->addMessageContent($text, $messageId);
          $streamedText .= $text;
        }
      }

      // Close text message if started.
      if ($messageStarted) {
        $this->agUiState->finishMessage($messageId);
      }

      // After iteration completes, the iterator has assembled all tool
      // call deltas into complete ToolsFunctionOutput objects.
      $assembledTools = $iterator->getTools();

      if (!empty($assembledTools)) {
        // Build a plain array of tool calls for SSE events and execution.
        $toolCallsForExec = [];
        $toolCallsForHistory = [];
        foreach ($assembledTools as $toolOutput) {
          $toolCallId = $toolOutput->getToolId() ?: $this->uuid->generate();
          $name = $toolOutput->getName();
          // getArguments() returns ToolsPropertyResult objects. Convert
          // back to a plain associative array for our tool handlers.
          $rawArgs = [];
          foreach ($toolOutput->getArguments() as $arg) {
            $rawArgs[$arg->getName()] = $arg->getValue();
          }
          $args = $rawArgs;

          $toolCallsForExec[$toolCallId] = [
            'name' => $name,
            'arguments' => $args,
          ];

          // Build the tool call array in the format the Mistral provider
          // expects from getRenderedTools() / ToolCallFunction::fromArray().
          $toolCallsForHistory[] = [
            'id' => $toolCallId,
            'type' => 'function',
            'index' => 0,
            'function' => [
              'name' => $name,
              'arguments' => Json::encode($args),
            ],
          ];

          // Emit tool call start via AgUiState.
          $this->agUiState->startToolCall($name, NULL, $toolCallId);
        }

        // Execute tool calls and add results to conversation history.
        $toolResults = $this->executeToolCalls($toolCallsForExec, $bundle, $fieldIndex);
        $messages[] = [
          'role' => 'assistant',
          'content' => $streamedText ?: '',
          'tool_calls_raw' => $toolCallsForHistory,
        ];
        foreach ($toolResults as $result) {
          $messages[] = $result;
        }
        $messageStarted = FALSE;
        $messageId = $this->uuid->generate();
        continue;
      }

      $fullMessage = $streamedText;
      break;
    }

    return $fullMessage;
  }

  /**
   * Executes tool calls and returns results as messages.
   *
   * @param array $toolCalls
   *   Tool calls keyed by tool call ID. Values are ToolsFunctionOutput.
   * @param string $bundle
   *   The content type bundle.
   * @param array $fieldIndex
   *   Flat field index from the content type schema, keyed by machine name.
   *
   * @return array
   *   Array of tool result messages.
   */
  private function executeToolCalls(array $toolCalls, string $bundle, array $fieldIndex): array {
    $results = [];

    foreach ($toolCalls as $toolCallId => $toolCall) {
      $name = $toolCall['name'] ?? '';
      $args = $toolCall['arguments'] ?? [];

      $result = match ($name) {
        'draft_content' => $this->toolDraftContent($args),
        default => ['error' => "Unknown tool: $name"],
      };

      $results[] = [
        'role' => 'tool',
        'content' => Json::encode($result),
        'tool_call_id' => $toolCallId,
      ];

      // Stream fields progressively so the frontend can render
      // each field as it arrives and show long text word-by-word.
      $this->streamFieldSnapshots($result['fields'] ?? [], $fieldIndex);

      // Close the tool call via AgUiState.
      $this->agUiState->finishToolCall($toolCallId);
    }

    return $results;
  }

  /**
   * Streams drafted fields progressively as STATE_SNAPSHOT events.
   *
   * When fieldsToStream is empty (first draft), all fields are
   * streamed progressively. When set (regeneration), only the
   * listed fields are streamed word-by-word; all others are sent
   * in a single initial snapshot. Field ordering is preserved.
   *
   * State snapshots are sent directly through the transporter since
   * AgUiState does not expose a method for this event type.
   *
   * @param array $fields
   *   Drafted fields keyed by machine name.
   * @param array $fieldIndex
   *   Schema field definitions keyed by machine name.
   */
  private function streamFieldSnapshots(array $fields, array $fieldIndex): void {
    // On regeneration, only send the requested fields. The frontend
    // merges partial snapshots with its existing state, so omitted
    // fields stay untouched. On first draft, send all fields.
    $targetFields = $fields;
    if (!empty($this->fieldsToStream)) {
      $targetFields = array_intersect_key(
        $fields,
        array_flip($this->fieldsToStream),
      );
    }

    $streamed = [];

    foreach ($targetFields as $name => $value) {
      $isLongText = $this->isStreamableField($name, $value, $fieldIndex);

      if ($isLongText && is_string($value) && mb_strlen($value) > 50) {
        $words = preg_split('/(\s+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $partial = '';
        foreach ($words as $word) {
          $partial .= $word;
          $streamed[$name] = $partial;
          $this->sendStateSnapshot(['draftedFields' => $streamed]);
        }
      }
      elseif ($isLongText && is_array($value) && isset($value['value'])
        && is_string($value['value']) && mb_strlen($value['value']) > 50) {
        $words = preg_split('/(\s+)/', $value['value'], -1, PREG_SPLIT_DELIM_CAPTURE);
        $partial = '';
        foreach ($words as $word) {
          $partial .= $word;
          $streamed[$name] = array_merge($value, ['value' => $partial]);
          $this->sendStateSnapshot(['draftedFields' => $streamed]);
        }
      }
      else {
        $streamed[$name] = $value;
        $this->sendStateSnapshot(['draftedFields' => $streamed]);
      }
    }
  }

  /**
   * Determines whether a field should be streamed word-by-word.
   *
   * Uses the widget type from the content type schema: textarea and
   * formatted text widgets are streamable; all others arrive whole.
   *
   * @param string $name
   *   The field machine name.
   * @param mixed $value
   *   The field value from the LLM.
   * @param array $fieldIndex
   *   Schema field definitions keyed by machine name.
   *
   * @return bool
   *   TRUE if the field should be streamed progressively.
   */
  private function isStreamableField(string $name, mixed $value, array $fieldIndex): bool {
    $widget = $fieldIndex[$name]['widget'] ?? '';
    return in_array($widget, [
      'textarea',
      'textarea_formatted',
      'textarea_formatted_summary',
    ], TRUE);
  }

  /**
   * Tool handler: draft_content.
   *
   * Returns the drafted fields as-is (validation can be added later).
   */
  private function toolDraftContent(array $args): array {
    $fields = $args['fields'] ?? $args;
    return [
      'success' => TRUE,
      'fields' => $fields,
      'message' => 'Draft content generated with ' . count($fields) . ' fields.',
    ];
  }

  /**
   * Loads conversation history from TempStore.
   */
  private function loadHistory(string $threadId): array {
    $store = $this->tempStoreFactory->get('oe_ai_drafting');
    $history = $store->get($threadId) ?? [];
    // Keep only the last N message pairs for context window.
    return array_slice($history, -(self::HISTORY_LENGTH * 2));
  }

  /**
   * Saves conversation history to TempStore.
   */
  private function saveHistory(string $threadId, array $history): void {
    $store = $this->tempStoreFactory->get('oe_ai_drafting');
    // Keep only the last N message pairs.
    $trimmed = array_slice($history, -(self::HISTORY_LENGTH * 2));
    $store->set($threadId, $trimmed);
  }

  /**
   * Wraps a raw tool call array into an object with getOutputRenderArray().
   *
   * ChatMessage::getRenderedTools() calls getOutputRenderArray() on each
   * tool. This wrapper satisfies that interface using the pre-built array.
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
