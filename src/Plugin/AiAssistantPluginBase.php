<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for AI Assistant plugins.
 *
 * Provides dispatch and helper methods. Concrete plugins must implement
 * getActionMap() to declare which URL actions are allowed and which
 * callables handle them.
 *
 * Action methods return an array (serialised to JSON by the controller)
 * or a Response (passed through as-is, e.g. for SSE streaming).
 * Errors are signalled by throwing ActionException.
 */
abstract class AiAssistantPluginBase extends PluginBase implements AiAssistantPluginInterface, ContainerFactoryPluginInterface {

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
   *   The action name from the URL.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   *
   * @return array|Response
   *   An array to be serialised as JSON, or a Response for streaming.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   If the action is not in the action map.
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
   * Decodes the JSON request body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  protected function decodeJsonBody(Request $request): array {
    $content = $request->getContent();
    $body = json_decode($content, TRUE);

    if (!is_array($body)) {
      return [];
    }

    return $body;
  }

}
