<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
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
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

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
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->logger = $container->get('logger.channel.oe_ai_assistant');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * A plugin that serves a conversation declares its own get-messages
   * action, reading from whichever store hosts it; this base registers
   * none. Plugins add their own actions by merging with
   * parent::getActionMap().
   */
  public function getActionMap(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestSchemas(): array {
    return [];
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

}
