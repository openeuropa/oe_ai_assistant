<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\FieldSnapshotStreamer;
use Drupal\oe_ai_assistant\Service\ConversationHistory;
use Drupal\oe_ai_assistant\Service\DraftFieldMapper;
use Drupal\oe_ai_assistant\Service\FormSchemaExtractor;
use Drupal\oe_ai_assistant\Service\LlmLoopConfig;
use Drupal\oe_ai_assistant\Service\LlmStreamingLoop;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drafting plugin: thin orchestrator for AI-powered content drafting.
 *
 * Delegates prompt building to DraftingPromptBuilder, conversation
 * history to ConversationHistory, the agentic tool-call loop to
 * LlmStreamingLoop, and field streaming to FieldSnapshotStreamer.
 * SSE events are emitted through the AG-UI state manager from the
 * base class.
 *
 * The plugin follows an RPC-style action model: each action (chat, reset,
 * save) is mapped by getActionMap() and dispatched by the base controller.
 * The "chat" action is the only streaming action; the others return plain
 * JSON arrays.
 */
#[AiEditorialAssistant(
  id: 'drafting',
  label: 'Drafting',
  description: 'AI-powered content drafting with AG-UI streaming.',
)]
class DraftingPlugin extends AiAssistantPluginBase {

  /**
   * Constructs a DraftingPlugin.
   *
   * All dependencies are injected via the static create() factory. Services
   * are declared as constructor-promoted properties so they are available
   * as $this->xyz throughout the class.
   *
   * @param array $configuration
   *   Plugin configuration array from the plugin manager.
   * @param string $plugin_id
   *   The plugin ID as declared in the AiEditorialAssistant attribute.
   * @param mixed $plugin_definition
   *   The plugin definition as resolved by the plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used indirectly via DraftFieldMapper.
   * @param \Drupal\oe_ai_assistant\Service\DraftFieldMapper $fieldMapper
   *   Maps LLM field values to Drupal field structures and creates nodes.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The currently authenticated Drupal user, used for permission checks.
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
   * @param \Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder $promptBuilder
   *   Builds the system prompt, tool definitions, and field index.
   */
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
    protected readonly ConversationHistory $conversationHistory,
    protected readonly LlmStreamingLoop $llmLoop,
    protected readonly DraftingPromptBuilder $promptBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * Resolves all dependencies from the service container and wires up
   * DraftingPromptBuilder, which is not registered as a Drupal service
   * and is therefore instantiated directly here.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The Drupal service container.
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   *
   * @return static
   *   A new instance of this plugin with all dependencies injected.
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
      $container->get(ConversationHistory::class),
      $container->get(LlmStreamingLoop::class),
      // DraftingPromptBuilder is not a Drupal service, so we instantiate
      // it here and pass in its own dependencies from the container.
      new DraftingPromptBuilder(
        $container->get(FormSchemaExtractor::class),
        $container->get('logger.factory')->get('oe_ai_assistant'),
      ),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Maps action names (as received in the URL path) to callable methods.
   * The base controller reads this map and dispatches accordingly.
   *
   * Note: "create" cannot be used as an action name because it collides
   * with the static create() factory inherited from the plugin interface.
   *
   * @return array<string, callable>
   *   Map of action name to callable.
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
   *
   * Returns the JSON Schema names used to validate incoming request bodies
   * for each action. Actions not listed here (e.g. "chat") are not
   * pre-validated -- the action handler performs its own validation.
   *
   * Schema files live in the module's config/schema/ directory and are
   * resolved by the request validation service.
   *
   * @return array<string, string>
   *   Map of action name to schema name.
   */
  public function getRequestSchemas(): array {
    return [
      'reset' => 'DraftingResetRequest',
      'save' => 'DraftingSaveRequest',
    ];
  }

  /**
   * Streams AI chat responses via AG-UI SSE.
   *
   * This is the main drafting action. It:
   *   1. Parses the user message from the AG-UI protocol request body.
   *   2. Extracts any [fields:name1,name2] hint for selective streaming.
   *   3. Builds the system prompt, tool definitions, and field index.
   *   4. Resolves the default AI provider and model.
   *   5. Opens an SSE response and, inside the streaming callback:
   *      a. Loads or initialises the conversation history for the thread.
   *      b. Runs the LLM tool-call loop via LlmStreamingLoop.
   *      c. Inside the tool executor, streams drafted fields progressively
   *         via FieldSnapshotStreamer.
   *      d. Saves the updated conversation history.
   *
   * All errors are caught, logged, and forwarded to the AG-UI error event
   * so the frontend can display a meaningful message.
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

    // Extract user message from AG-UI protocol format.
    // The frontend may send a top-level "message" string (simple format)
    // or a "messages" array in the OpenAI conversation format. We support
    // both and extract the last user-role message in the array case.
    $message = $body['message'] ?? '';
    if (empty($message) && !empty($body['messages'])) {
      // Filter to user-role messages and take the last one.
      $userMessages = array_filter(
        $body['messages'],
        fn($m) => ($m['role'] ?? '') === 'user',
      );
      $lastUserMessage = end($userMessages);
      // Content may be a plain string or an array of content parts
      // (e.g. [{ "type": "text", "text": "..." }]).
      $message = is_array($lastUserMessage['content'] ?? '')
        ? implode('', array_map(fn($p) => $p['text'] ?? '', $lastUserMessage['content']))
        : ($lastUserMessage['content'] ?? '');
    }

    // The thread ID correlates history across multiple chat turns.
    $threadId = $body['threadId'] ?? '';

    // forwardedProps carry CMS-specific context (entity type, bundle) that
    // the frontend appends transparently; fall back to top-level keys for
    // backwards compatibility.
    $forwardedProps = $body['forwardedProps'] ?? [];
    $entityTypeId = $forwardedProps['entityTypeId'] ?? $body['entityTypeId'] ?? 'node';
    $bundle = $forwardedProps['bundle'] ?? $body['bundle'] ?? '';

    if (empty($message)) {
      throw new ActionException('invalid_request', 'Message is required.', 400);
    }

    // Parse [fields:name1,name2] tag from the user message as a hint
    // for which fields to stream progressively. This is set by the
    // frontend regenerate button. The LLM also declares changed_fields
    // in its tool call, which overrides this hint if present.
    $fieldsToStream = [];
    if (preg_match('/\[fields:([^\]]+)\]/', $message, $matches)) {
      $fieldsToStream = explode(',', $matches[1]);
    }

    // Build prompt, tools, and field index via the prompt builder.
    // These are computed before opening the SSE response so that any
    // schema extraction errors surface as HTTP 500 rather than mid-stream.
    $systemPrompt = $this->promptBuilder->buildSystemPrompt($entityTypeId, $bundle);
    $tools = $this->promptBuilder->buildTools();
    $fieldIndex = $this->promptBuilder->buildFieldIndex($entityTypeId, $bundle);

    // Resolve the default provider and model for "chat" operation type.
    // The provider ID and model ID are passed into the LLM loop config.
    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
    $providerId = $defaults['provider_id'];
    $modelId = $defaults['model_id'];

    // Open the SSE response. Everything inside the callback is executed
    // after the HTTP headers have been sent, so we cannot throw exceptions
    // that bubble up to the controller -- errors must go through $state.
    return $this->createSseResponse(function () use (
      $systemPrompt, $message, $threadId, $tools, $providerId, $modelId,
      $fieldIndex, $fieldsToStream,
    ) {
      // Disable PHP's execution time limit for long-running streams.
      set_time_limit(0);

      // Create the AG-UI state manager which tracks run/message lifecycle
      // and emits the appropriate SSE events (RUN_STARTED, RUN_FINISHED,
      // ERROR, etc.).
      $state = $this->createAgUiState();
      $runId = $this->uuid->generate();

      // If the caller supplied a thread ID, reuse it so history is
      // continuous across turns; otherwise generate a fresh one.
      $sseThreadId = !empty($threadId) ? $threadId : $this->uuid->generate();
      $messageId = $this->uuid->generate();

      $state->startRun($sseThreadId, $runId);

      try {
        // Load existing conversation history for this thread so the LLM
        // has context from previous turns.
        $history = $this->conversationHistory->load('oe_ai_drafting', $sseThreadId);

        // An empty history means this is the very first message in the
        // thread; used later to decide streaming mode for all text fields.
        $isFirstDraft = empty($history);

        // Append the new user message to the history before sending it
        // to the LLM. The LLM loop receives the full history array.
        $history[] = ['role' => 'user', 'content' => $message];

        // $activeFieldsToStream is mutable: the tool executor may override
        // it with the LLM's own changed_fields declaration. We use a
        // reference so the override is visible outside the closure.
        $activeFieldsToStream = $fieldsToStream;

        // Build the tool executor closure passed to LlmStreamingLoop.
        // It is called once per LLM tool-call round-trip with the list
        // of tool calls the model wants to invoke.
        $toolExecutor = function (array $toolCalls) use (
          $fieldIndex, &$activeFieldsToStream, $isFirstDraft,
        ): array {
          $results = [];
          foreach ($toolCalls as $toolCallId => $toolCall) {
            $name = $toolCall['name'] ?? '';
            $args = $toolCall['arguments'] ?? [];

            // Dispatch each tool call to the appropriate local handler.
            // Currently only draft_content is supported; unknown tools
            // return an error payload that the LLM can act on.
            $result = match ($name) {
              'draft_content' => $this->toolDraftContent($args),
              default => ['error' => "Unknown tool: $name"],
            };

            // The tool result is fed back to the LLM as a "tool" role
            // message so it can continue the conversation with the result.
            $results[] = [
              'role' => 'tool',
              'content' => Json::encode($result),
              'tool_call_id' => $toolCallId,
            ];

            // If the LLM declared changed_fields in the tool arguments,
            // override the frontend hint so only genuinely modified
            // fields are streamed progressively.
            if (!empty($result['changedFields'])) {
              $activeFieldsToStream = $result['changedFields'];
            }

            // Stream field values to the frontend as STATE_SNAPSHOT SSE
            // events. Progressive (word-by-word) streaming is applied to
            // long text fields; short and non-text fields arrive whole.
            $streamer = new FieldSnapshotStreamer($this->transporter);
            $streamer->stream(
              $result['fields'] ?? [],
              $fieldIndex,
              $activeFieldsToStream,
              $isFirstDraft,
            );
          }
          return $results;
        };

        // Run the agentic LLM tool-call loop. The loop sends the
        // conversation history to the LLM, handles any tool calls the
        // model makes (by invoking $toolExecutor), and continues until
        // the model produces a final text response.
        $config = new LlmLoopConfig(
          systemPrompt: $systemPrompt,
          conversationHistory: $history,
          tools: $tools,
          providerId: $providerId,
          modelId: $modelId,
          messageId: $messageId,
          toolExecutor: $toolExecutor,
        );
        $loopResult = $this->llmLoop->run($this->agUiState, $config);

        // Persist the updated conversation history so the next chat turn
        // in this thread has full context.
        $updatedHistory = $loopResult->messages;

        // If the model produced a final plain-text reply (i.e. no tool
        // call on the last turn), append it to the history as well.
        if (!empty($loopResult->assistantText)) {
          $updatedHistory[] = [
            'role' => 'assistant',
            'content' => $loopResult->assistantText,
          ];
        }
        $this->conversationHistory->save('oe_ai_drafting', $sseThreadId, $updatedHistory);
      }
      catch (\Exception $e) {
        // Log the full error server-side and emit an AG-UI error event
        // so the frontend can display a user-facing message.
        $this->logger->error('Error in drafting chat: @error', [
          '@error' => $e->getMessage(),
        ]);
        $state->errorRun($e->getMessage());
      }

      // Always emit RUN_FINISHED to signal the end of the SSE stream,
      // even if an error occurred, so the frontend can stop loading.
      $state->finishRun();
    });
  }

  /**
   * Resets the conversation thread.
   *
   * Deletes the stored history for the given thread ID and returns a fresh
   * thread ID that the frontend should use for subsequent chat requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request. Body must conform to DraftingResetRequest schema.
   *
   * @return array<string, string>
   *   An array with a single "threadId" key containing the new thread ID.
   */
  public function reset(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $threadId = $body['threadId'] ?? '';

    // Only attempt deletion if a thread ID was provided; a missing or
    // empty thread ID means there is nothing to clear.
    if (!empty($threadId)) {
      $this->conversationHistory->delete('oe_ai_drafting', $threadId);
    }

    // Always return a fresh thread ID so the frontend can start a new
    // conversation without needing to generate its own UUID.
    return ['threadId' => $this->uuid->generate()];
  }

  /**
   * Creates a Drupal node from an approved draft.
   *
   * Called when the editor clicks "Save" in the drafting UI after reviewing
   * the AI-generated content. Delegates field mapping to DraftFieldMapper,
   * which handles type coercion, entity references, and the actual node save.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request. Body must conform to DraftingSaveRequest schema
   *   and must include "bundle" and "fields" keys.
   *
   * @return array<string, string>
   *   An array with "nodeId" and "previewUrl" keys for the created node.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   With code "forbidden" (HTTP 403) if the user lacks create permission.
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   With code "invalid_bundle" (HTTP 400) if the bundle is not recognised.
   */
  public function save(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $bundle = $body['bundle'] ?? '';
    $fields = $body['fields'] ?? [];

    // Check that the current user can create content of this bundle.
    // This mirrors Drupal's standard "create {bundle} content" permission.
    if (!$this->currentUser->hasPermission("create $bundle content")) {
      throw new ActionException(
        'forbidden',
        sprintf('You do not have permission to create %s content.', $bundle),
        403,
      );
    }

    try {
      // DraftFieldMapper handles field type coercion and node creation.
      $node = $this->fieldMapper->createNode($bundle, $fields);
    }
    catch (\InvalidArgumentException $e) {
      // The mapper throws InvalidArgumentException for unrecognised bundles
      // or fields; surface this as a 400 Bad Request to the API consumer.
      throw new ActionException('invalid_bundle', $e->getMessage(), 400);
    }

    return [
      'nodeId' => (string) $node->id(),
      'previewUrl' => $this->fieldMapper->getPreviewUrl($node),
    ];
  }

  /**
   * Tool handler: draft_content.
   *
   * Handles the draft_content LLM tool call. Normalises the arguments from
   * the model (which may nest fields inside an "args.fields" key or return
   * them flat) and returns a structured result for the tool executor.
   *
   * The "changedFields" value in the return array is used by the tool
   * executor to override the frontend streaming hint: if the LLM declares
   * which fields it changed, we stream only those progressively.
   *
   * @param array $args
   *   The tool call arguments as decoded from the LLM's JSON response.
   *   Expected keys: "fields" (object) and "changed_fields" (array).
   *
   * @return array<string, mixed>
   *   An array with keys:
   *   - "success": bool, always TRUE on a valid call.
   *   - "fields": array of field values keyed by machine name.
   *   - "changedFields": array of field machine names that were modified.
   *   - "message": human-readable summary for the tool result.
   */
  private function toolDraftContent(array $args): array {
    // The LLM may wrap the field values under an "fields" key or return
    // them directly at the top level of $args; handle both shapes.
    $fields = $args['fields'] ?? $args;

    // Extract the changed_fields array, defaulting to empty if absent or
    // not an array (which would indicate a malformed tool call from the LLM).
    $changedFields = [];
    if (!empty($args['changed_fields']) && is_array($args['changed_fields'])) {
      $changedFields = $args['changed_fields'];
    }

    return [
      'success' => TRUE,
      'fields' => $fields,
      'changedFields' => $changedFields,
      'message' => 'Draft content generated with ' . count($fields) . ' fields.',
    ];
  }

}
