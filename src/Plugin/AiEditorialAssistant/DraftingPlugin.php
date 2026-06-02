<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionPluginInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Service\AiEditorialSessionPluginStore;
use Drupal\oe_ai_assistant\Service\DraftEntityBuilder;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use Drupal\oe_ai_assistant\Store\ConversationStoreFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drafting plugin: AI-powered content drafting with SSE streaming.
 *
 * Uses Drupal AI's provider plugin manager for LLM calls with
 * streaming, ai_agents config entities for system prompt and tool
 * definitions, and UiMessageStream for SSE output.
 */
#[AiEditorialAssistant(
  id: 'drafting',
  label: 'Drafting',
  description: 'AI-powered content drafting with SSE streaming.',
)]
class DraftingPlugin extends AiAssistantPluginBase {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * The AI agent plugin manager.
   *
   * @var \Drupal\ai_agents\PluginManager\AiAgentManager
   */
  protected AiAgentManager $aiAgentManager;

  /**
   * The UI message stream service.
   *
   * @var \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface
   */
  protected UiMessageStreamInterface $uiMessageStream;

  /**
   * The conversation store factory.
   *
   * @var \Drupal\oe_ai_assistant\Store\ConversationStoreFactoryInterface
   */
  protected ConversationStoreFactoryInterface $conversationStoreFactory;

  /**
   * Logger channel for oe_ai_assistant.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The JSON Schema composer service.
   *
   * @var \Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer
   */
  protected EntityJsonSchemaComposer $schemaComposer;

  /**
   * Per-request schema cache keyed by "entityTypeId:bundle".
   *
   * @var array<string, array>
   */
  private array $cachedSchema = [];

  /**
   * The current Drupal user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Content moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected ModerationInformationInterface $moderationInformation;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The draft entity builder.
   *
   * @var \Drupal\oe_ai_assistant\Service\DraftEntityBuilder
   */
  protected DraftEntityBuilder $draftEntityBuilder;

  /**
   * Plugin instance repository.
   *
   * @var \Drupal\oe_ai_assistant\Service\AiEditorialSessionPluginStore
   */
  protected AiEditorialSessionPluginStore $pluginInstanceStore;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->aiProviderManager = $container->get('ai.provider');
    $instance->aiAgentManager = $container->get('plugin.manager.ai_agents');
    $instance->uiMessageStream = $container->get('oe_ai_assistant.ui_message_stream');
    $instance->conversationStoreFactory = $container->get('oe_ai_assistant.conversation_store_factory');
    $instance->logger = $container->get('logger.factory')->get('oe_ai_assistant');
    $instance->schemaComposer = $container->get(EntityJsonSchemaComposer::class);
    $instance->currentUser = $container->get('current_user');
    $instance->moderationInformation = $container->get('content_moderation.moderation_information');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->draftEntityBuilder = $container->get(DraftEntityBuilder::class);
    $instance->pluginInstanceStore = $container->get(AiEditorialSessionPluginStore::class);
    return $instance;
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
   * Streams an AI chat response via SSE.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with a chat message body.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   An SSE streaming response.
   */
  public function chat(Request $request): Response {
    $body = $this->decodeJsonBody($request);
    $message = $this->extractUserMessage($body);

    if (empty($message)) {
      throw new ActionException(
        'invalid_request', 'Message is required.', 400,
      );
    }

    $context = $this->buildContext($body);
    $pluginInstance = $this->loadSessionPluginInstance($body);
    $threadId = $this->resolveThreadId($body, $pluginInstance);

    $store = $this->conversationStoreFactory
      ->getStore('oe_ai_drafting', $threadId);

    // Load conversation history and append the user's message.
    $history = $store->load();
    $history[] = new ChatMessage('user', $message);

    // Load the router agent config entity for system prompt and tools.
    $router = $this->aiAgentManager->createInstance('oe_drafting_router');

    // Append content type schema to the agent's system prompt.
    $systemPrompt = $router->getSystemPrompt()
      . $this->buildSchemaText(
        $context['entityTypeId'], $context['bundle']
      );

    // Build ChatInput with the full conversation history.
    $chatInput = new ChatInput($history);
    $chatInput->setStreamedOutput(TRUE);
    $chatInput->setSystemPrompt($systemPrompt);

    $chatInput->setChatTools(new ToolsInput([$this->buildDraftTool()]));

    // Resolve the provider for chat_with_tools since we always
    // include the draft_content tool definition.
    $defaults = $this->aiProviderManager
      ->getDefaultProviderForOperationType('chat_with_tools');
    $provider = $this->aiProviderManager
      ->createInstance($defaults['provider_id']);
    $chatOutput = $provider->chat(
      $chatInput, $defaults['model_id'], ['drafting']
    );

    // Stream the response using UiMessageStream.
    $fieldIndex = $this->buildFieldIndex(
      $context['entityTypeId'], $context['bundle']
    );

    return $this->uiMessageStream->respond(
      function (UiMessageStreamInterface $stream) use (
        $chatOutput, $history, $fieldIndex, $store, $threadId,
      ): void {
        $stream->start();

        // Emit the threadId so the frontend can persist it and
        // send it back on subsequent requests for history continuity.
        $stream->customEvent('data-thread-id', [
          'threadId' => $threadId,
        ]);

        // Stream the LLM response and collect any tool calls.
        $toolCalls = $stream->streamChatOutput($chatOutput, 'router');

        // Check if draft_content was called.
        $draftCall = NULL;
        foreach ($toolCalls as $tool) {
          if ($tool->getName() === 'draft_content') {
            $draftCall = $tool;
            break;
          }
        }

        if ($draftCall !== NULL) {
          // Extract fields from tool call arguments.
          $fields = [];
          foreach ($draftCall->getArguments() as $arg) {
            if ($arg->getName() === 'fields') {
              $fields = $arg->getValue();
            }
          }

          // Filter fields against the schema.
          if (!empty($fieldIndex)) {
            $unknownFields = array_diff_key($fields, $fieldIndex);
            if (!empty($unknownFields)) {
              $this->logger->notice(
                'LLM generated unknown fields: @fields',
                ['@fields' => implode(', ', array_keys($unknownFields))],
              );
            }
            $fields = array_intersect_key($fields, $fieldIndex);
          }

          // Emit data-drafted-fields for the frontend content table.
          $stream->customEvent('data-drafted-fields', $fields);

          // Emit a text confirmation so the chat UI shows a response
          // instead of infinite loading dots.
          $confirmText = 'Draft generated with '
            . count($fields) . ' fields. Review the content on the right.';
          $stream->startStep('confirmation');
          $stream->textDelta($confirmText);
          $stream->finishStep('confirmation');

          // Persist the drafted result in history.
          $history[] = new ChatMessage('assistant', $confirmText);
          $store->save($history);
        }
        else {
          // Persist the text response in history.
          $reconstructed = $chatOutput->getNormalized();
          if ($reconstructed instanceof StreamedChatMessageIteratorInterface) {
            $output = $reconstructed->reconstructChatOutput();
            $responseText = $output->getNormalized()->getText() ?? '';
          }
          else {
            $responseText = $reconstructed->getText() ?? '';
          }
          if ($responseText !== '') {
            $history[] = new ChatMessage('assistant', $responseText);
          }
          $store->save($history);
        }

        $stream->finish($draftCall ? 'tool_calls' : 'stop');
      }
    );
  }

  /**
   * Resets the conversation thread.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, string>
   *   A confirmation response.
   */
  public function reset(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $threadId = $body['threadId'] ?? '';
    if (!empty($threadId)) {
      $this->conversationStoreFactory
        ->getStore('oe_ai_drafting', $threadId)
        ->drop();
    }
    return ['threadId' => bin2hex(random_bytes(16))];
  }

  /**
   * Saves a drafted node built from the LLM-produced fields map.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The save request.
   *
   * @return array<string, string>
   *   An array with `nodeId` and `previewUrl`.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   On invalid bundle, missing permission, or builder rejection.
   */
  public function save(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $bundle = $body['bundle'] ?? '';
    $fields = $body['fields'] ?? [];

    if (!$this->entityTypeManager->getStorage('node_type')->load($bundle)) {
      throw new ActionException('invalid_bundle',
        sprintf('Content type "%s" does not exist.', $bundle), 400);
    }

    if (!$this->currentUser->hasPermission("create $bundle content")) {
      throw new ActionException(
        'forbidden',
        sprintf('You do not have permission to create %s content.', $bundle),
        403,
      );
    }

    try {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->draftEntityBuilder->fromLlmFields('node', $bundle, $fields);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to build draft entity: @e', [
        '@e' => (string) $e,
      ]);
      throw new ActionException(
        'invalid_payload',
        'The submitted draft payload could not be processed. See the system log for details.',
        400,
      );
    }

    $node->setOwnerId((int) $this->currentUser->id());
    if ($this->moderationInformation->isModeratedEntity($node)) {
      $node->set('moderation_state', 'draft');
    }
    else {
      $node->setPublished(FALSE);
    }
    $node->save();

    return [
      'nodeId' => (string) $node->id(),
      'previewUrl' => $this->buildPreviewUrl($node),
    ];
  }

  /**
   * Builds the draft_content tool definition.
   *
   * Defines the tool schema inline so the LLM can return structured
   * field values. No FunctionCall plugin class needed; the tool call
   * result is handled directly after streaming.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput
   *   The draft_content tool definition.
   */
  private function buildDraftTool(): ToolsFunctionInput {
    // Note: 'required' is set via setRequired() rather than in the
    // property array to avoid rendering it as a boolean inside the
    // property schema (providers reject that).
    $fieldsProp = new ToolsPropertyInput('fields', [
      'type' => 'object',
      'description' => 'An object mapping field machine names to their values.',
    ]);
    $fieldsProp->setRequired(TRUE);

    $changedProp = new ToolsPropertyInput('changed_fields', [
      'type' => 'array',
      'description' => 'List of field machine names that were created or modified.',
      'items' => ['type' => 'string'],
    ]);

    $tool = new ToolsFunctionInput();
    $tool->setName('draft_content');
    $tool->setDescription(
      'Generate or update field values for a content draft.'
      . ' Call this whenever the user asks to draft, create,'
      . ' update, or modify content fields.'
    );
    $tool->setProperties([$fieldsProp, $changedProp]);

    return $tool;
  }

  /**
   * Builds the preview URL for a freshly saved node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The newly saved node.
   *
   * @return string
   *   Relative URL for the node preview.
   */
  private function buildPreviewUrl(NodeInterface $node): string {
    return $this->moderationInformation->isModeratedEntity($node)
      ? '/node/' . $node->id() . '/latest'
      : '/node/' . $node->id();
  }

  /**
   * Extracts the user message from the request body.
   *
   * @param array $body
   *   The decoded request body.
   *
   * @return string
   *   The user message text, or empty string.
   */
  private function extractUserMessage(array $body): string {
    $message = $body['message'] ?? '';
    if (!empty($message)) {
      return $message;
    }
    if (empty($body['messages'])) {
      return '';
    }
    $userMessages = array_filter(
      $body['messages'],
      fn($m) => ($m['role'] ?? '') === 'user',
    );
    $last = end($userMessages);
    if (is_array($last['content'] ?? '')) {
      return implode('', array_map(
        fn($p) => $p['text'] ?? '',
        $last['content'],
      ));
    }
    return $last['content'] ?? '';
  }

  /**
   * Builds drafting context from the request body.
   *
   * @param array $body
   *   The decoded request body.
   *
   * @return array
   *   Context with entityTypeId and bundle.
   */
  private function buildContext(array $body): array {
    $forwardedProps = $body['forwardedProps'] ?? [];
    $entityTypeId = $forwardedProps['entityTypeId']
      ?? $body['entityTypeId'] ?? 'node';
    $bundle = $forwardedProps['bundle']
      ?? $body['bundle'] ?? '';
    return [
      'entityTypeId' => $entityTypeId,
      'bundle' => $bundle,
    ];
  }

  /**
   * Loads or creates the drafting plugin instance for a session-backed chat.
   *
   * @param array $body
   *   The decoded request body.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionPluginInterface|null
   *   The session plugin instance, or NULL for non-session requests.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the session ID is invalid or inaccessible.
   */
  private function loadSessionPluginInstance(array $body): ?AiEditorialSessionPluginInterface {
    $forwardedProps = $body['forwardedProps'] ?? [];
    $sessionId = $forwardedProps['sessionId'] ?? $body['sessionId'] ?? NULL;
    if (empty($sessionId)) {
      return NULL;
    }

    $session = $this->entityTypeManager
      ->getStorage('ai_editorial_session')
      ->load($sessionId);
    if (!$session instanceof AiEditorialSessionInterface) {
      throw new ActionException(
        'invalid_session',
        sprintf('AI editorial session "%s" does not exist.', $sessionId),
        404,
      );
    }

    if (!$session->access('update', $this->currentUser)) {
      throw new ActionException(
        'forbidden',
        'You do not have access to update this AI editorial session.',
        403,
      );
    }

    return $this->pluginInstanceStore
      ->loadOrCreateForSession($session, 'drafting');
  }

  /**
   * Resolves the drafting thread ID for session and non-session requests.
   *
   * @param array $body
   *   The decoded request body.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionPluginInterface|null $pluginInstance
   *   The session plugin instance, or NULL for non-session requests.
   *
   * @return string
   *   The resolved thread ID.
   */
  private function resolveThreadId(array $body, ?AiEditorialSessionPluginInterface $pluginInstance): string {
    if ($pluginInstance !== NULL) {
      $threadId = $pluginInstance->getStateValue('threadId');
      if (is_string($threadId) && $threadId !== '') {
        return $threadId;
      }

      $threadId = bin2hex(random_bytes(16));
      $pluginInstance->setStateValue('threadId', $threadId);
      $pluginInstance->save();
      return $threadId;
    }

    $threadId = $body['threadId'] ?? '';
    return is_string($threadId) && $threadId !== ''
      ? $threadId
      : bin2hex(random_bytes(16));
  }

  /**
   * Builds a flat field index from the content type schema.
   *
   * Used to filter LLM-generated fields against known field names.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. "node").
   * @param string $bundle
   *   The bundle machine name (e.g. "article").
   *
   * @return array<string, array>
   *   Field schemas keyed by machine name, or empty array on failure.
   */
  private function buildFieldIndex(string $entityTypeId, string $bundle): array {
    if (empty($bundle)) {
      return [];
    }
    try {
      $schema = $this->getComposedSchema($entityTypeId, $bundle);
      return $schema['properties'] ?? [];
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Returns the content type schema as text for the system prompt.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. "node").
   * @param string $bundle
   *   The bundle machine name (e.g. "oe_news").
   *
   * @return string
   *   The schema as "\n\nContent type schema:\n{json}", or empty
   *   string if the bundle is empty or composition fails.
   */
  private function buildSchemaText(string $entityTypeId, string $bundle): string {
    if (empty($bundle)) {
      return '';
    }
    try {
      $schema = $this->getComposedSchema($entityTypeId, $bundle);
      return "\n\nContent type schema:\n" . Json::encode($schema);
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not load schema for @type/@bundle: @error', [
        '@type' => $entityTypeId,
        '@bundle' => $bundle,
        '@error' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Returns the composed schema, using a per-request cache.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   *
   * @return array<string, mixed>
   *   The composed schema array.
   */
  private function getComposedSchema(string $entityTypeId, string $bundle): array {
    $cacheKey = "$entityTypeId:$bundle";
    if (!isset($this->cachedSchema[$cacheKey])) {
      $this->cachedSchema[$cacheKey] = $this->schemaComposer->compose(
        $entityTypeId,
        $bundle,
      );
    }
    return $this->cachedSchema[$cacheKey];
  }

}
