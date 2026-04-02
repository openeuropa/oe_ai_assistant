<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\Component\Plugin\Exception\PluginException;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for AI Assistant plugins.
 *
 * Provides Drupal plugin dispatch (action routing, request
 * validation) and HTTP utilities (JSON body decoding, SSE
 * response creation). No AI or streaming logic.
 *
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginInterface
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager
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
   * Creates an SSE EventStreamResponse.
   *
   * Uses Symfony's EventStreamResponse which handles SSE headers,
   * output buffer flushing, and connection management natively.
   * The callback is wrapped to disable gzip compression.
   *
   * @param callable $callback
   *   The streaming callback. Use the EventStreamResponse's
   *   sendEvent() method to emit ServerEvent instances.
   *
   * @return \Symfony\Component\HttpFoundation\EventStreamResponse
   *   The configured SSE response.
   */
  protected function createSseResponse(callable $callback): EventStreamResponse {
    $wrappedCallback = function (EventStreamResponse $response) use ($callback) {
      ini_set('zlib.output_compression', '0');
      ini_set('implicit_flush', '1');
      if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
      }
      ($callback)($response);
    };

    return new EventStreamResponse($wrappedCallback, 200, [
      'x-vercel-ai-ui-message-stream' => 'v1',
    ]);
  }

}
