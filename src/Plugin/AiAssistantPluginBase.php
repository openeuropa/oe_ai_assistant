<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Neuron\Chat\History\SessionConversation;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for AI Assistant plugins.
 *
 * Provides Drupal plugin dispatch (action routing, request
 * validation), HTTP utilities (JSON body decoding, user message
 * extraction), and the shared logger and recorder.
 *
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginInterface
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager
 */
abstract class AiAssistantPluginBase extends PluginBase implements AiAssistantPluginInterface, ContainerFactoryPluginInterface {

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
   * Logger channel for oe_ai_assistant.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Reads a stored conversation back.
   *
   * @var \Drupal\oe_ai_assistant\Neuron\Chat\History\SessionConversation
   */
  protected SessionConversation $conversation;

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
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->logger = $container->get('logger.channel.oe_ai_assistant');
    $instance->conversation = $container->get(SessionConversation::class);
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
   * {@inheritdoc}
   */
  public function getQueryActions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Most plugins need no bootstrap configuration; plugins that do override
   * this method.
   */
  public function getAppConfig(AiEditorialSessionInterface $session, RefinableCacheableDependencyInterface $cacheability): array {
    return [];
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

    return ['messages' => $this->conversationEntries($session)];
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
   * Renders the conversation of one session for the client.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   *
   * @return array
   *   The turns, in insertion order.
   */
  private function conversationEntries(AiEditorialSessionInterface $session): array {
    $rows = $this->conversation->rows($session, $this->getPluginId());
    $authors = $this->loadAuthors($rows);

    $entries = [];
    // Where each call was rendered, so the result that arrives in a later row
    // can be put on it: the call id points at the entry and the call in it.
    $calls = [];

    foreach ($rows as $row) {
      $meta = SessionConversation::decode($row, 'meta');
      $created = (int) $row->get('created')->value;

      // A tool result carries the user role, because Neuron's result message
      // extends its user message. It is not something the editor said: its
      // payload belongs on the call that asked for it.
      if (($meta['type'] ?? '') === 'tool_call_result') {
        foreach ($meta['tools'] ?? [] as $tool) {
          $at = $calls[$tool['callId'] ?? ''] ?? NULL;
          if ($at !== NULL) {
            $entries[$at[0]]['toolCalls'][$at[1]]['result'] = SessionConversation::decodeResult($tool['result'] ?? NULL);
          }
        }
        continue;
      }

      $role = (string) $row->get('role')->value;
      if (!in_array($role, ['user', 'assistant'], TRUE)) {
        continue;
      }

      $text = $this->renderText(SessionConversation::decode($row, 'content'));
      $toolCalls = [];
      foreach ($meta['tools'] ?? [] as $tool) {
        $inputs = $tool['inputs'] ?? [];
        $calls[$tool['callId'] ?? ''] = [count($entries), count($toolCalls)];
        $toolCalls[] = [
          'id' => $tool['callId'] ?? NULL,
          'type' => 'function',
          // An empty input list has to encode as an object, since the client
          // parses the arguments of every call the same way.
          'function' => [
            'name' => $tool['name'] ?? '',
            'arguments' => json_encode($inputs === [] ? new \stdClass() : $inputs),
          ],
        ];
      }

      // Skip a turn that carries neither text nor a call, such as the empty
      // answer a model returns alongside its tool calls.
      if ($text === '' && $toolCalls === []) {
        continue;
      }

      $item = [
        'role' => $role,
        'content' => $text,
        'at' => $this->formatTime($created),
      ];
      // Attribute user turns to their author for shared sessions. The uid
      // keeps same-named users apart; the display name is what the client
      // renders.
      $uid = (int) $row->get('uid')->target_id;
      if ($role === 'user' && isset($authors[$uid])) {
        $item['userId'] = (string) $uid;
        $item['userName'] = (string) $authors[$uid]->getDisplayName();
      }
      if ($toolCalls !== []) {
        $item['toolCalls'] = $toolCalls;
      }

      $entries[] = $item;
    }

    return $entries;
  }

  /**
   * Loads the authors of the user rows in one query.
   *
   * @param array $rows
   *   The conversation rows.
   *
   * @return \Drupal\user\UserInterface[]
   *   The accounts, keyed by uid.
   */
  private function loadAuthors(array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
      $uid = (int) $row->get('uid')->target_id;
      if ($uid > 0) {
        $ids[$uid] = $uid;
      }
    }

    return $ids === [] ? [] : $this->entityTypeManager->getStorage('user')->loadMultiple($ids);
  }

  /**
   * Joins the text of a message's content blocks.
   *
   * @param array $blocks
   *   The decoded content column, which is a list of content blocks.
   *
   * @return string
   *   The text, empty for a message that carries none.
   */
  private function renderText(array $blocks): string {
    $text = '';
    foreach ($blocks as $block) {
      if (($block['type'] ?? '') === 'text') {
        $text .= (string) ($block['content'] ?? '');
      }
    }

    return $text;
  }

  /**
   * Formats a timestamp as RFC 3339, so clients can render local times.
   */
  private function formatTime(int $timestamp): string {
    return \DateTimeImmutable::createFromFormat('U', (string) $timestamp)->format('c');
  }

}
