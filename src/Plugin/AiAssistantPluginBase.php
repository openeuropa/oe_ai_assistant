<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\ai\Response\AiStreamedResponse;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Serialization\Json;
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

  /**
   * Creates an SSE streaming response.
   *
   * Uses AiStreamedResponse from drupal/ai which handles output buffer
   * clearing and sets the correct headers (X-Accel-Buffering, Cache-Control,
   * Surrogate-Control) automatically. Plugins only need to call flush()
   * after each chunk inside the callback.
   *
   * @param callable $callback
   *   The streaming callback. Will be executed after output buffers are
   *   cleared by AiStreamedResponse::sendContent().
   *
   * @return \Drupal\ai\Response\AiStreamedResponse
   *   The configured streaming response.
   */
  protected function createSseResponse(callable $callback): AiStreamedResponse {
    return new AiStreamedResponse($callback, 200, [
      'Content-Type' => 'text/event-stream',
      'Connection' => 'keep-alive',
    ]);
  }

  /**
   * Sends an SSE event to the client.
   *
   * Outputs an AG-UI protocol event as an SSE "data:" line with a JSON
   * payload. The first event includes 4 KB of padding to force proxy
   * buffers (nginx/FastCGI) to flush immediately.
   *
   * @param array $data
   *   The event data. Must include a 'type' key.
   * @param bool $isFirst
   *   Whether this is the first event in the stream (adds proxy padding).
   */
  protected function sendSseEvent(array $data, bool $isFirst = FALSE): void {
    // Send padding to overcome proxy buffering on the first event.
    if ($isFirst) {
      echo ": " . str_repeat(" ", 4096) . "\n\n";
      flush();
    }

    if (empty($data) || !isset($data['type'])) {
      return;
    }

    $json = Json::encode($data);
    if ($json === FALSE || $json === 'null') {
      return;
    }

    echo "data: " . $json . "\n\n";
    flush();
  }

}
