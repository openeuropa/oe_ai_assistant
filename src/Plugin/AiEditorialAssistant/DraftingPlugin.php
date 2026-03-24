<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Swis\AgUiServer\Events\StateSnapshotEvent;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder;
use Drupal\oe_ai_assistant\Plugin\ChatPluginBase;
use Drupal\oe_ai_assistant\Service\ConversationHistory;
use Drupal\oe_ai_assistant\Service\DraftFieldMapper;
use Drupal\oe_ai_assistant\Service\FormSchemaExtractor;
use Drupal\oe_ai_assistant\Service\LlmStreamingLoop;
use Drupal\ai\PluginManager\AiShortTermMemoryPluginManager;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drafting plugin: AI-powered content drafting with AG-UI streaming.
 *
 * Extends ChatPluginBase to inherit the full LLM chat lifecycle (message
 * extraction, SSE streaming, conversation history, error handling) and
 * implements the four abstract hooks to supply drafting-specific prompts,
 * tools, and tool executors.
 *
 * Drafting-specific responsibilities:
 *   - Building the system prompt and tool definitions via
 *     DraftingPromptBuilder.
 *   - Executing the draft_content tool call and streaming field snapshots.
 *   - Saving approved drafts as Drupal nodes via DraftFieldMapper.
 */
#[AiEditorialAssistant(
  id: 'drafting',
  label: 'Drafting',
  description: 'AI-powered content drafting with AG-UI streaming.',
)]
class DraftingPlugin extends ChatPluginBase {

  /**
   * Constructs a DraftingPlugin.
   *
   * The seven chat services (aiProvider, uuid, logger, conversationHistory,
   * llmLoop, shortTermMemoryManager, functionCallManager) are forwarded to
   * ChatPluginBase via parent::__construct().
   * Only drafting-specific services are declared as promoted properties
   * on this class.
   *
   * @param array $configuration
   *   Plugin configuration array from the plugin manager.
   * @param string $plugin_id
   *   The plugin ID as declared in the AiEditorialAssistant attribute.
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
   *   Plugin manager for auto-discovered FunctionCall plugins.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used indirectly via DraftFieldMapper.
   * @param \Drupal\oe_ai_assistant\Service\DraftFieldMapper $fieldMapper
   *   Maps LLM field values to Drupal field structures and creates nodes.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The currently authenticated Drupal user, used for permission checks.
   * @param \Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder $promptBuilder
   *   Builds the system prompt, tool definitions, and field index.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AiProviderPluginManager $aiProvider,
    UuidInterface $uuid,
    LoggerInterface $logger,
    ConversationHistory $conversationHistory,
    LlmStreamingLoop $llmLoop,
    AiShortTermMemoryPluginManager $shortTermMemoryManager,
    FunctionCallPluginManager $functionCallManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DraftFieldMapper $fieldMapper,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly DraftingPromptBuilder $promptBuilder,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $aiProvider,
      $uuid,
      $logger,
      $conversationHistory,
      $llmLoop,
      $shortTermMemoryManager,
      $functionCallManager,
    );
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
      // Chat services (forwarded to ChatPluginBase).
      $container->get('ai.provider'),
      $container->get('uuid'),
      $container->get('logger.factory')->get('oe_ai_assistant'),
      $container->get(ConversationHistory::class),
      $container->get(LlmStreamingLoop::class),
      $container->get('plugin.manager.ai.short_term_memory'),
      $container->get('plugin.manager.ai.function_calls'),
      // Drafting-specific services.
      $container->get('entity_type.manager'),
      $container->get(DraftFieldMapper::class),
      $container->get('current_user'),
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
   * The "chat" and "reset" actions are inherited from ChatPluginBase.
   * "save" is handled locally.
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
   * {@inheritdoc}
   *
   * Extracts drafting-specific context from the request body. This includes
   * the entity type, bundle, field index, and any [fields:...] hint from
   * the user message for selective field streaming.
   */
  protected function buildChatContext(
    array $body,
    string $message,
  ): array {
    // forwardedProps carry CMS-specific context (entity type, bundle) that
    // the frontend appends transparently; fall back to top-level keys for
    // backwards compatibility.
    $forwardedProps = $body['forwardedProps'] ?? [];
    $entityTypeId = $forwardedProps['entityTypeId']
      ?? $body['entityTypeId'] ?? 'node';
    $bundle = $forwardedProps['bundle']
      ?? $body['bundle'] ?? '';
    return [
      'entityTypeId' => $entityTypeId,
      'bundle' => $bundle,
      'fieldIndex' => $this->promptBuilder
        ->buildFieldIndex($entityTypeId, $bundle),
      'fieldsToStream' => $this->parseFieldsHint($message),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Delegates system prompt construction to DraftingPromptBuilder, which
   * includes the content type schema and drafting instructions.
   */
  protected function buildSystemPrompt(array $context): string {
    return $this->promptBuilder->buildSystemPrompt(
      $context['entityTypeId'],
      $context['bundle'],
    );
  }

  /**
   * {@inheritdoc}
   *
   * Delegates tool definition construction to DraftingPromptBuilder.
   */
  protected function buildTools(array $context): ToolsInput {
    return $this->promptBuilder->buildTools();
  }

  /**
   * {@inheritdoc}
   *
   * Creates a closure that executes drafting tool calls (draft_content)
   * and streams field snapshots to the frontend. The closure captures
   * the field index and streaming hint from the context.
   */
  protected function createToolExecutor(
    array $context,
    bool $isFirstTurn,
  ): \Closure {
    $fieldIndex = $context['fieldIndex'];
    $activeFieldsToStream = $context['fieldsToStream'];
    return function (array $toolCalls) use (
      $fieldIndex, &$activeFieldsToStream, $isFirstTurn,
    ): array {
      return $this->executeToolCalls(
        $toolCalls,
        $fieldIndex,
        $activeFieldsToStream,
        $isFirstTurn,
      );
    };
  }

  /**
   * Parses [fields:name1,name2] hint from the user message.
   *
   * This tag is set by the frontend regenerate button to indicate which
   * fields should be streamed progressively. The LLM's own changed_fields
   * declaration overrides this hint if present.
   *
   * @param string $message
   *   The user message text.
   *
   * @return string[]
   *   Array of field machine names to stream, or empty array.
   */
  private function parseFieldsHint(string $message): array {
    if (preg_match('/\[fields:([^\]]+)\]/', $message, $matches)) {
      return explode(',', $matches[1]);
    }
    return [];
  }

  /**
   * Executes tool calls from the LLM and streams field snapshots.
   *
   * Dispatches each tool call to the appropriate handler, builds tool
   * result messages for the conversation history, and streams field
   * values to the frontend via SSE STATE_SNAPSHOT events.
   *
   * @param array $toolCalls
   *   Map of tool call ID to tool call data (name + arguments).
   * @param array $fieldIndex
   *   The field index mapping field names to metadata.
   * @param string[] &$activeFieldsToStream
   *   Fields to stream progressively; updated by reference if the LLM
   *   declares changed_fields.
   * @param bool $isFirstDraft
   *   Whether this is the first message in the thread.
   *
   * @return array
   *   Tool result messages for the conversation history.
   */
  private function executeToolCalls(
    array $toolCalls,
    array $fieldIndex,
    array &$activeFieldsToStream,
    bool $isFirstDraft,
  ): array {
    $results = [];

    foreach ($toolCalls as $toolCallId => $toolCall) {
      $name = $toolCall['name'] ?? '';
      $args = $toolCall['arguments'] ?? [];

      // Dispatch each tool call to the appropriate local handler.
      // draft_content is handled locally; all other tool names are
      // delegated to the FunctionCall plugin system.
      $result = match ($name) {
        'draft_content' => $this->toolDraftContent($args),
        default => $this->executeFunctionCallPlugin($name, $args),
      };

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

      // Emit the final reconciliation snapshot. Incremental field
      // streaming (STATE_DELTA events) was already handled by
      // ToolCallFieldStreamer during the streaming iteration, so
      // we only need the authoritative final state here.
      $this->transporter->sendEvent(
        new StateSnapshotEvent(
          ['draftedFields' => $result['fields'] ?? []],
        ),
      );
    }

    return $results;
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
