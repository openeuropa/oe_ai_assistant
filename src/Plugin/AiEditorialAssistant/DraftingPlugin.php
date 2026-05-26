<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder;
use Drupal\oe_ai_assistant\Service\DraftEntityBuilder;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use Drupal\oe_ai_assistant\Store\ConversationStoreInterface;
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
   * The conversation store for this plugin's threads.
   *
   * @var \Drupal\oe_ai_assistant\Store\ConversationStoreInterface
   */
  protected ConversationStoreInterface $conversationStore;

  /**
   * Logger channel for oe_ai_assistant.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The prompt builder for schema composition.
   *
   * @var \Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder
   */
  protected DraftingPromptBuilder $promptBuilder;

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
    $instance->conversationStore = $container->get('oe_ai_assistant.conversation_store_factory')
      ->getStore('oe_ai_drafting', 'conversation');
    $instance->logger = $container->get('logger.factory')->get('oe_ai_assistant');
    $instance->promptBuilder = new DraftingPromptBuilder(
      $container->get(EntityJsonSchemaComposer::class),
      $instance->logger,
    );
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

    // Load conversation history and append the user's message.
    $history = $this->conversationStore->load();
    $history[] = new ChatMessage('user', $message);

    // Load the router agent config entity for system prompt and tools.
    $router = $this->aiAgentManager->createInstance('oe_drafting_router');

    // Append content type schema to the agent's system prompt.
    $systemPrompt = $router->getSystemPrompt()
      . $this->promptBuilder->buildSchemaText(
        $context['entityTypeId'], $context['bundle']
      );

    // Build ChatInput with the full conversation history.
    $chatInput = new ChatInput($history);
    $chatInput->setStreamedOutput(TRUE);
    $chatInput->setSystemPrompt($systemPrompt);
    $functions = $router->getFunctions();
    if (!empty($functions['normalized'])) {
      $chatInput->setChatTools(new ToolsInput($functions['normalized']));
    }

    // Get the default provider and call the LLM directly.
    $defaults = $this->aiProviderManager
      ->getDefaultProviderForOperationType('chat');
    $provider = $this->aiProviderManager
      ->createInstance($defaults['provider_id']);
    $chatOutput = $provider->chat(
      $chatInput, $defaults['model_id'], ['drafting']
    );

    // Stream the response using UiMessageStream.
    return $this->uiMessageStream->respond(
      function (UiMessageStreamInterface $stream) use (
        $chatOutput, $history, $context,
      ): void {
        $stream->start();

        // Stream the router response and collect tool calls.
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
          $this->handleDraftToolCall(
            $stream, $draftCall, $history, $context
          );
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
          $this->conversationStore->save($history);
        }

        $stream->finish($draftCall ? 'tool_calls' : 'stop');
      }
    );
  }

  /**
   * Handles the draft_content tool call result.
   *
   * Extracts field values from the tool call arguments, filters
   * against the known field index, emits a data-drafted-fields
   * SSE event, and persists the result in conversation history.
   *
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The SSE stream.
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface $draftCall
   *   The draft_content tool call.
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $history
   *   The conversation history (mutated in place).
   * @param array $context
   *   The drafting context with entityTypeId, bundle, fieldIndex.
   */
  protected function handleDraftToolCall(
    UiMessageStreamInterface $stream,
    $draftCall,
    array &$history,
    array $context,
  ): void {
    // Extract fields from tool call arguments.
    $fields = [];
    $changedFields = [];
    foreach ($draftCall->getArguments() as $arg) {
      if ($arg->getName() === 'fields') {
        $fields = $arg->getValue();
      }
      if ($arg->getName() === 'changed_fields') {
        $changedFields = $arg->getValue();
      }
    }

    // Filter fields against the schema.
    $fieldIndex = $context['fieldIndex'];
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

    // Emit data-drafted-fields event.
    $stream->customEvent('data-drafted-fields', $fields);

    // Persist the drafted result in history.
    $history[] = new ChatMessage('assistant', Json::encode([
      'fields' => $fields,
      'changedFields' => $changedFields,
    ]));
    $this->conversationStore->save($history);
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
    $this->conversationStore->drop();
    return ['status' => 'ok'];
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

  // -- Private helpers -------------------------------------------------------

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
   *   Context with entityTypeId, bundle, and fieldIndex.
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
      'fieldIndex' => $this->promptBuilder
        ->buildFieldIndex($entityTypeId, $bundle),
    ];
  }

  /**
   * Formats an exception into a user-friendly error message.
   *
   * @param \Exception $e
   *   The caught exception.
   *
   * @return string
   *   A user-facing error message.
   */
  private function formatErrorForChat(\Exception $e): string {
    $msg = $e->getMessage();
    if (preg_match('/\b401\b|unauthorized/i', $msg)) {
      return 'The AI service rejected the API key.';
    }
    if (preg_match('/\b429\b|rate.?limit/i', $msg)) {
      return 'The AI service is overloaded. Try again shortly.';
    }
    if (preg_match('/\b5\d{2}\b|server error/i', $msg)) {
      return 'The AI service is temporarily unavailable.';
    }
    if (preg_match('/timeout|timed? out/i', $msg)) {
      return 'The AI service did not respond in time.';
    }
    return $msg;
  }

}
