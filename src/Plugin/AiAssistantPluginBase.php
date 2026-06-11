<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use Drupal\oe_ai_assistant\Store\ConversationStoreFactoryInterface;
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
 * conversation store, logger).
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
    $instance->conversationStoreFactory = $container->get(ConversationStoreFactoryInterface::class);
    $instance->logger = $container->get('logger.channel.oe_ai_assistant');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestSchemas(): array {
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

}
