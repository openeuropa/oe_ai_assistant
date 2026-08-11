<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for AI Assistant plugins.
 *
 * Provides Drupal plugin dispatch (action routing, request
 * validation), HTTP utilities (JSON body decoding, user message
 * extraction), and shared AI infrastructure (provider, stream,
 * logger).
 *
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginInterface
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager
 */
abstract class AiAssistantPluginBase extends PluginBase implements AiAssistantPluginInterface, ContainerFactoryPluginInterface {

  /**
   * Maximum top-level turns replayed to the model as history.
   */
  protected const MAX_HISTORY = 40;

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * The UI message stream service.
   *
   * @var \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface
   */
  protected UiMessageStreamInterface $uiMessageStream;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The message recorder.
   *
   * @var \Drupal\oe_ai_assistant\Service\MessageRecorderInterface
   */
  protected MessageRecorderInterface $messageRecorder;

  /**
   * Logger channel for oe_ai_assistant.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

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
    $instance->uiMessageStream = $container->get(UiMessageStreamInterface::class);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->messageRecorder = $container->get(MessageRecorderInterface::class);
    $instance->logger = $container->get('logger.channel.oe_ai_assistant');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Every plugin exposes the shared get-messages action, so any conversation
   * hosted by an editorial session can be retrieved. Plugins add their own
   * actions by merging with parent::getActionMap().
   */
  public function getActionMap(): array {
    return [
      'get-messages' => $this->getMessages(...),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestSchemas(): array {
    return [
      'get-messages' => 'GetMessagesRequest',
    ];
  }

  /**
   * Returns the user-visible transcript for the session.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array
   *   An array with a `messages` list of {role, content} entries.
   */
  public function getMessages(Request $request): array {
    $session = $this->loadSession($this->decodeJsonBody($request));
    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    $messages = [];
    foreach ($storage->loadTranscript($session) as $message) {
      $role = $message->getRole();
      // Event rows surface as compact timeline entries.
      if ($role === 'event') {
        $messages[] = [
          'role' => 'event',
          'type' => (string) ($message->getMetadata()['type'] ?? ''),
          'summary' => (string) $message->get('content')->value,
          'at' => date('c', (int) $message->get('created')->value),
        ];
        continue;
      }
      // Only user and assistant turns are shown to the editor.
      if (!in_array($role, ['user', 'assistant'], TRUE)) {
        continue;
      }
      $content = (string) $message->get('content')->value;
      $toolCalls = $message->getToolCalls();
      // Skip empty turns that carry neither text nor a tool call.
      if ($content === '' && !$toolCalls) {
        continue;
      }
      $item = ['role' => $role, 'content' => $content];
      if ($toolCalls) {
        $item['toolCalls'] = $toolCalls;
      }
      $messages[] = $item;
    }
    return ['messages' => $messages];
  }

  /**
   * Dispatches a request to the callable registered in getActionMap().
   *
   * @param string $action
   *   The action name from the URL path.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   *
   * @return array|\Symfony\Component\HttpFoundation\Response
   *   An array (serialised as JSON) or a Response (for SSE).
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   When the action is not in getActionMap().
   */
  public function executeAction(string $action, Request $request): array|Response {
    $map = $this->getActionMap();
    if (!isset($map[$action])) {
      throw new PluginException(sprintf(
        "Action '%s' is not available on plugin '%s'.",
        $action,
        $this->getPluginId(),
      ));
    }
    return ($map[$action])($request);
  }

  /**
   * Decodes the JSON request body into an associative array.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, mixed>
   *   The decoded body, or an empty array on invalid JSON.
   */
  protected function decodeJsonBody(Request $request): array {
    $body = json_decode($request->getContent(), TRUE);
    return is_array($body) ? $body : [];
  }

  /**
   * Extracts the user message from the request body.
   *
   * Handles both the simple `message` field and the Vercel AI SDK
   * `messages` array format. Any chat plugin can use this to parse
   * user input regardless of the client format.
   *
   * @param array $body
   *   The decoded request body.
   *
   * @return string
   *   The user message text, or empty string.
   */
  protected function extractUserMessage(array $body): string {
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
   * Loads and access-checks the editorial session named in the body.
   *
   * @param array $body
   *   The decoded request body.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The session hosting the conversation.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the sessionId is missing, unknown, or access is denied.
   */
  protected function loadSession(array $body): AiEditorialSessionInterface {
    $sessionId = $body['sessionId'] ?? '';
    if ($sessionId === '') {
      throw new ActionException('invalid_request', 'A sessionId is required.', 400);
    }
    $session = $this->entityTypeManager->getStorage('ai_editorial_session')
      ->load($sessionId);
    if (!$session instanceof AiEditorialSessionInterface) {
      throw new ActionException('invalid_request', 'The editorial session was not found.', 404);
    }
    if (!$session->access('view', $this->currentUser)) {
      throw new ActionException('forbidden', 'Access to the editorial session is denied.', 403);
    }
    return $session;
  }

  /**
   * Loads the persisted transcript as chat history for the model.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage[]
   *   The capped top-level transcript as ChatMessage objects.
   */
  protected function buildHistory(AiEditorialSessionInterface $session): array {
    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    // Skip persisted tool results: a bare chat message cannot re-link
    // them to the assistant call that produced them, and providers
    // reject unpaired tool messages. The assistant's follow-up text
    // already carries the outcome.
    $entities = array_filter(
      $storage->loadTranscript($session),
      fn($m) => $m->getRole() !== 'tool',
    );
    // Slice AFTER filtering so tool rows do not consume history slots.
    $entities = array_slice($entities, -static::MAX_HISTORY);
    return array_map(
      function ($m) {
        $content = (string) $m->get('content')->value;
        // Editorial events enter the history as compact notes.
        // The user role is used because mid-history system messages are
        // provider-dependent, and the bracket prefix marks the note as
        // non-conversational.
        if ($m->getRole() === 'event') {
          return new ChatMessage('user', '[Editorial change] ' . $content);
        }
        return new ChatMessage($m->getRole(), $content);
      },
      $entities,
    );
  }

}
