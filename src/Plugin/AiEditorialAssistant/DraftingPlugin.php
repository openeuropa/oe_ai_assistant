<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
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
 * Uses a two-tool conversational flow:
 * 1. get_content_schema: LLM discovers available fields
 * 2. draft_content: LLM signals readiness, orchestrator dispatches
 *    sub-agents per field group.
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
   * Supports a multi-turn tool flow: the LLM can call
   * get_content_schema (executed by ai_agents), chat with the
   * user, then call draft_content to trigger sub-agent
   * orchestration.
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
    $threadId = $body['threadId'] ?? '';

    if (empty($threadId)) {
      $threadId = bin2hex(random_bytes(16));
    }
    $store = $this->conversationStoreFactory
      ->getStore('oe_ai_drafting', $threadId);

    // Load conversation history and append the user's message.
    $history = $store->load();
    $history[] = new ChatMessage('user', $message);

    // Load the router agent config entity for system prompt and
    // tools (get_content_schema is registered there).
    $router = $this->aiAgentManager->createInstance('oe_drafting_router');

    // Build the system prompt: agent base + bundle/entityTypeId
    // hint so the LLM knows what values to pass to
    // get_content_schema.
    $systemPrompt = $router->getSystemPrompt()
      . "\n\nContent type context:\n"
      . "bundle: " . $context['bundle'] . "\n"
      . "entity_type_id: " . $context['entityTypeId'] . "\n";

    // Collect tools: get_content_schema from agent config +
    // inline draft_content signal tool.
    $functions = $router->getFunctions();
    $tools = [];
    if (!empty($functions['normalized'])) {
      $tools = $functions['normalized'];
    }
    $tools[] = $this->buildDraftTool();

    // Build ChatInput with the full conversation history.
    $chatInput = new ChatInput($history);
    $chatInput->setStreamedOutput(TRUE);
    $chatInput->setSystemPrompt($systemPrompt);
    $chatInput->setChatTools(new ToolsInput($tools));

    // Resolve the provider for chat_with_tools.
    $defaults = $this->aiProviderManager
      ->getDefaultProviderForOperationType('chat_with_tools');
    $provider = $this->aiProviderManager
      ->createInstance($defaults['provider_id']);
    $chatOutput = $provider->chat(
      $chatInput, $defaults['model_id'], ['drafting']
    );

    // Stream the response using UiMessageStream.
    return $this->uiMessageStream->respond(
      function (UiMessageStreamInterface $stream) use (
        $chatOutput, $history, $store, $threadId, $context,
      ): void {
        $stream->start();

        $stream->customEvent('data-thread-id', [
          'threadId' => $threadId,
        ]);

        // Stream the LLM response and collect any tool calls.
        $toolCalls = $stream->streamChatOutput($chatOutput, 'router');

        // Check for draft_content (orchestration signal).
        $draftCall = NULL;
        foreach ($toolCalls as $tool) {
          if ($tool->getName() === 'draft_content') {
            $draftCall = $tool;
            break;
          }
        }

        if ($draftCall !== NULL) {
          // Run the sub-agent orchestration loop.
          $this->orchestrate($stream, $history, $context);

          // Persist confirmation in history.
          $history[] = new ChatMessage('assistant',
            'Draft content generated.');
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
   * Runs the sub-agent orchestration loop.
   *
   * Splits the content type schema into groups, dispatches one
   * sub-agent per group, streams plan/step progress, consolidates
   * results, and emits data-drafted-fields.
   *
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The SSE stream.
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $history
   *   The conversation history for sub-agent context.
   * @param array $context
   *   The drafting context with entityTypeId and bundle.
   */
  protected function orchestrate(
    UiMessageStreamInterface $stream,
    array $history,
    array $context,
  ): void {
    // Split schema into groups using the composer service.
    $groups = $this->schemaComposer->splitSchemaIntoGroups(
      $context['entityTypeId'], $context['bundle']
    );

    if (empty($groups)) {
      $stream->textDelta('No fields available for drafting.');
      return;
    }

    // Build the plan array and emit initial state (all pending).
    $plan = array_map(fn($g) => [
      'stepId' => $g['groupId'],
      'label' => $g['label'],
      'status' => 'pending',
    ], $groups);
    $stream->customEvent('data-plan', $plan);

    // Serialize conversation history for sub-agent context.
    $conversationContext = '';
    foreach ($history as $msg) {
      $conversationContext .= $msg->getRole() . ': '
        . $msg->getText() . "\n";
    }

    $results = [];
    $mainFieldsResult = '';

    foreach ($groups as $index => $group) {
      $stepId = $group['groupId'];

      // Update plan: mark this step as in_progress.
      $plan[$index]['status'] = 'in_progress';
      $stream->customEvent('data-plan', $plan);

      try {
        // Load a fresh instance of the sub-agent config entity.
        $agent = $this->aiAgentManager
          ->createInstance('oe_content_drafter');

        // Set structured output on the agent entity.
        $agent->getAiAgentEntity()
          ->set('structured_output_enabled', TRUE);
        $agent->getAiAgentEntity()
          ->set('structured_output_schema', json_encode([
            'name' => $stepId,
            'schema' => $group['schemaSlice'],
          ]));

        // Build task prompt with conversation context.
        // Subsequent sub-agents receive the main fields result
        // as additional context.
        $taskPrompt = "Conversation context:\n$conversationContext\n";
        if ($stepId !== 'main_fields' && $mainFieldsResult !== '') {
          $taskPrompt .= "Main fields already generated:\n"
            . $mainFieldsResult . "\n\n";
        }
        $taskPrompt .= "Generate content for the fields in the "
          . "provided schema. Follow the conversation context.";

        $task = new Task($taskPrompt);
        $agent->setTask($task);

        // Run the agent.
        $solvability = $agent->determineSolvability();
        $fullText = '';
        if ($solvability === AiAgentInterface::JOB_SOLVABLE) {
          $fullText = $agent->solve() ?? '';
        }
        elseif ($solvability === AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION) {
          $fullText = $agent->answerQuestion() ?? '';
        }

        // Parse the JSON result.
        $parsed = $stream->extractJson($fullText);
        if (is_array($parsed)) {
          $results[$stepId] = $parsed;
          if ($stepId === 'main_fields') {
            $mainFieldsResult = $fullText;
          }
        }

        // Update plan: mark this step as done.
        $plan[$index]['status'] = 'done';
        $stream->customEvent('data-plan', $plan);
      }
      catch (\Exception $e) {
        $this->logger->error('Sub-agent @step failed: @error', [
          '@step' => $stepId,
          '@error' => $e->getMessage(),
        ]);
        $plan[$index]['status'] = 'error';
        $stream->customEvent('data-plan', $plan);
        $stream->error($e->getMessage(), $stepId);
      }
    }

    // Consolidate all sub-agent results into a single flat fields
    // map. Main fields are top-level; entity reference groups are
    // merged by their field name.
    $consolidated = [];
    foreach ($groups as $group) {
      $stepId = $group['groupId'];
      if (!isset($results[$stepId])) {
        continue;
      }
      if ($stepId === 'main_fields') {
        $consolidated = array_merge($consolidated, $results[$stepId]);
      }
      else {
        // Entity reference groups: the result should be keyed by
        // field name, or we wrap it under the group's field name.
        foreach ($group['fieldNames'] as $fieldName) {
          if (isset($results[$stepId][$fieldName])) {
            $consolidated[$fieldName] = $results[$stepId][$fieldName];
          }
          else {
            // The sub-agent returned the value directly without
            // wrapping it under the field name.
            $consolidated[$fieldName] = $results[$stepId];
          }
        }
      }
    }

    // Emit data-drafted-fields for the frontend content table.
    $stream->customEvent('data-drafted-fields', $consolidated);

    // Emit a text confirmation.
    $fieldCount = count($consolidated);
    $confirmText = "Draft generated with $fieldCount fields."
      . ' Review the content on the right.';
    $stream->startStep('confirmation');
    $stream->textDelta($confirmText);
    $stream->finishStep('confirmation');
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
   * Builds the draft_content signal tool definition.
   *
   * No arguments. When the LLM calls this, the orchestrator takes
   * over and dispatches sub-agents per field group.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput
   *   The draft_content tool definition.
   */
  private function buildDraftTool(): ToolsFunctionInput {
    $tool = new ToolsFunctionInput();
    $tool->setName('draft_content');
    $tool->setDescription(
      'Signal that you are ready to generate the content draft.'
      . ' Call this after you have gathered enough information'
      . ' from the user. The system will generate field values'
      . ' automatically using sub-agents.'
    );
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

}
