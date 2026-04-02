<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\ToolCallFieldStreamer;
use Drupal\oe_ai_assistant\Plugin\ChatPluginBase;
use Drupal\oe_ai_assistant\Service\AgentFactory;
use Drupal\oe_ai_assistant\Service\DraftFieldMapper;
use Drupal\oe_ai_assistant\Service\FormSchemaExtractor;
use Drupal\oe_ai_assistant\Tool\DraftContentTool;
use Drupal\ai\PluginManager\AiShortTermMemoryPluginManager;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drafting plugin: AI-powered content drafting with Data Stream Protocol.
 *
 * Extends ChatPluginBase to inherit the full LLM chat lifecycle (message
 * extraction, SSE streaming, conversation history, error handling) and
 * implements the four abstract hooks to supply drafting-specific prompts,
 * tools, and tool executors.
 *
 * Drafting-specific responsibilities:
 *   - Building the system prompt and tool definitions via
 *     DraftingPromptBuilder.
 *   - Executing the draft_content tool call and streaming field data events.
 *   - Saving approved drafts as Drupal nodes via DraftFieldMapper.
 */
#[AiEditorialAssistant(
  id: 'drafting',
  label: 'Drafting',
  description: 'AI-powered content drafting with SSE streaming.',
)]
class DraftingPlugin extends ChatPluginBase {

  /**
   * Constructs a DraftingPlugin.
   *
   * The chat services (uuid, logger, agentFactory, tempStoreFactory,
   * shortTermMemoryManager, functionCallManager) are forwarded to
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
    UuidInterface $uuid,
    LoggerInterface $logger,
    AgentFactory $agentFactory,
    PrivateTempStoreFactory $tempStoreFactory,
    AiShortTermMemoryPluginManager $shortTermMemoryManager,
    FunctionCallPluginManager $functionCallManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DraftFieldMapper $fieldMapper,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly DraftingPromptBuilder $promptBuilder,
  ) {
    parent::__construct(
      $configuration, $plugin_id, $plugin_definition,
      $uuid, $logger, $agentFactory, $tempStoreFactory,
      $shortTermMemoryManager, $functionCallManager,
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
      $container->get('uuid'),
      $container->get('logger.factory')->get('oe_ai_assistant'),
      $container->get(AgentFactory::class),
      $container->get('tempstore.private'),
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
   * the entity type, bundle, and field index for schema-aware drafting.
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
   * Creates a DraftContentTool instance with the current transporter
   * and field index, and pairs it with the Tool metadata from
   * DraftingPromptBuilder.
   */
  protected function buildTools(array $context): array {
    $tool = new DraftContentTool(
      $context['fieldIndex'],
      $this->logger,
    );
    $metadata = $this->promptBuilder->buildToolMetadata();
    return [
      ['instance' => $tool, 'metadata' => $metadata],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Registers a ToolCallSucceeded listener that emits a
   * data-drafted-fields event when the draft_content tool completes.
   * This is the correct integration point for SSE side effects --
   * tools themselves stay pure, and the event fires at the right
   * time in Symfony AI's lifecycle (after execution, before the
   * agent re-invokes the LLM).
   */
  protected function registerToolEventListeners(
    EventDispatcher $eventDispatcher,
    array $context,
    bool $isFirstTurn,
  ): void {
    $fieldIndex = $context['fieldIndex'];

    $eventDispatcher->addListener(
      ToolCallSucceeded::class,
      function (ToolCallSucceeded $event) use ($fieldIndex): void {
        $toolCall = $event->getResult()->getToolCall();
        if ($toolCall->getName() !== 'draft_content') {
          return;
        }

        // Parse the tool result to extract validated fields.
        $resultData = json_decode(
          (string) $event->getResult()->getResult(), TRUE,
        );
        $draftedFields = $resultData['fields'] ?? [];

        // Filter against field index (same validation the tool
        // already applied, but defensive here).
        if (!empty($fieldIndex)) {
          $draftedFields = array_intersect_key(
            $draftedFields, $fieldIndex,
          );
        }

        // Emit reconciliation event with validated fields.
        $this->emitEvent('data-drafted-fields', [
          'data' => $draftedFields,
        ]);
      },
    );
  }

  /**
   * {@inheritdoc}
   *
   * Creates a ToolCallFieldStreamer that processes partial tool call
   * argument JSON in real time, emitting data-drafted-fields events
   * as field values grow token by token from the LLM.
   */
  protected function createToolCallDeltaObserver(
    array $context,
    bool $isFirstTurn,
  ): ?\Closure {
    $fieldIndex = $context['fieldIndex'];
    $emitter = $this->emitEvent(...);
    $streamer = new ToolCallFieldStreamer($emitter, $fieldIndex);
    return function (string $toolName, array $decoded) use ($streamer): void {
      if ($toolName === 'draft_content') {
        $streamer->onDelta($decoded);
      }
    };
  }

}
