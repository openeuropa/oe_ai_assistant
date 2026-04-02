<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\ai\Response\AiStreamedResponse;
use Drupal\Component\Plugin\Exception\PluginException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ServerEvent;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for AI Assistant plugins.
 *
 * Concrete plugins extend this class and implement getActionMap() to declare
 * which URL action names are allowed and which callables handle them. All
 * other dispatch logic, SSE setup, and body decoding is provided here so that
 * individual plugins can focus on business logic.
 *
 * Action return contract
 * ----------------------
 * Action methods may return:
 *   - An array: the PluginController wraps it in a JsonResponse.
 *   - A Response object: passed through unchanged (use this for SSE streams).
 *
 * Errors should be signalled by throwing ActionException, which the
 * controller converts to an appropriate HTTP error response.
 *
 * SSE streaming
 * -------------
 * Streaming uses the Vercel AI SDK UI Message Stream Protocol v1. Events
 * are emitted as standard SSE "data:" lines via Symfony's ServerEvent.
 * The emitEvent() helper JSON-encodes payload arrays and flushes each
 * event immediately to the client.
 *
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginInterface
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager
 */
abstract class AiAssistantPluginBase extends PluginBase implements AiAssistantPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The AiStreamedResponse for the current SSE stream.
   *
   * Set by createSseResponse() and used by emitEvent() to send
   * events during the streaming callback. NULL outside of an
   * active SSE stream.
   *
   * @var \Drupal\ai\Response\AiStreamedResponse|null
   */
  protected ?AiStreamedResponse $sseResponse = NULL;

  /**
   * {@inheritdoc}
   *
   * Returns an empty array by default, meaning no action on this plugin
   * requires request body validation. Concrete plugins override this method
   * to opt specific actions into schema validation.
   */
  public function getRequestSchemas(): array {
    return [];
  }

  /**
   * Dispatches a request to the callable registered in getActionMap().
   *
   * Looks up the action name in the map returned by getActionMap(). If
   * found, invokes the registered callable with the incoming request and
   * returns its result. If the action name is not in the map, a
   * PluginException is thrown (the controller converts this to a 404).
   *
   * @param string $action
   *   The action name extracted from the URL path segment after the plugin ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request, including headers and body.
   *
   * @return array|\Symfony\Component\HttpFoundation\Response
   *   An array to be serialised as JSON, or a Response for SSE streaming.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   When the requested action is not listed in getActionMap().
   */
  public function executeAction(string $action, Request $request): array|Response {
    $map = $this->getActionMap();

    // Guard against unknown actions. Only actions explicitly declared in
    // getActionMap() are permitted to prevent unintended method exposure.
    if (!isset($map[$action])) {
      throw new PluginException(sprintf(
        "Action '%s' is not available on plugin '%s'.",
        $action,
        $this->getPluginId(),
      ));
    }

    // Invoke the registered callable. The callable receives the full request
    // and is responsible for reading the body and returning a result.
    return ($map[$action])($request);
  }

  /**
   * Decodes the JSON request body into a PHP associative array.
   *
   * Most plugin actions expect a JSON body. This helper centralises the
   * decode so concrete actions do not need to repeat the json_decode call.
   * If the body is absent, empty, or not a valid JSON object/array, an empty
   * array is returned rather than throwing, so callers can apply defaults.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request whose body will be decoded.
   *
   * @return array<string, mixed>
   *   The decoded body as an associative array, or an empty array on failure.
   */
  protected function decodeJsonBody(Request $request): array {
    $content = $request->getContent();
    $body = json_decode($content, TRUE);

    // json_decode returns NULL for invalid JSON and non-NULL scalars for
    // valid JSON primitives. Only accept array-shaped bodies.
    if (!is_array($body)) {
      return [];
    }

    return $body;
  }

  /**
   * Emits an SSE event using the UI Message Stream Protocol.
   *
   * JSON-encodes the payload with the given type as the discriminator
   * field, wraps it in a Symfony ServerEvent, and sends it through the
   * active AiStreamedResponse. Each event is flushed immediately.
   *
   * @param string $type
   *   The event type (e.g. 'text-delta', 'tool-call-start',
   *   'data-drafted-fields').
   * @param array<string, mixed> $data
   *   Additional event payload fields merged with the type.
   */
  protected function emitEvent(string $type, array $data = []): void {
    $data['type'] = $type;
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $this->sendServerEvent(new ServerEvent($json));
  }

  /**
   * Emits the [DONE] sentinel to signal end of stream.
   *
   * The assistant-stream client library requires this marker
   * to close the connection cleanly.
   */
  protected function emitDone(): void {
    $this->sendServerEvent(new ServerEvent('[DONE]'));
  }

  /**
   * Writes a ServerEvent to the output buffer and flushes.
   *
   * @param \Symfony\Component\HttpFoundation\ServerEvent $event
   *   The SSE event to send.
   */
  private function sendServerEvent(ServerEvent $event): void {
    foreach ($event as $part) {
      echo $part;
    }
    if (ob_get_level() > 0) {
      @ob_flush();
    }
    flush();
  }

  /**
   * Creates and returns a Symfony Response configured for SSE streaming.
   *
   * SSE (Server-Sent Events) requires the response to remain open while the
   * server pushes data. This method uses AiStreamedResponse from drupal/ai,
   * which clears all existing PHP output buffers before invoking the callback.
   *
   * The callback is wrapped to disable gzip compression and enable implicit
   * flush before the plugin's streaming logic runs.
   *
   * @param callable $callback
   *   The streaming callback containing the plugin's SSE logic. It will be
   *   executed after output buffers have been cleared. The callback should
   *   use emitEvent() and emitDone() to send events.
   *
   * @return \Drupal\ai\Response\AiStreamedResponse
   *   A streaming response with SSE headers set.
   */
  protected function createSseResponse(callable $callback): AiStreamedResponse {
    // Wrap the callback to disable compression and enable implicit flush
    // before the plugin's streaming logic runs.
    $wrappedCallback = function () use ($callback) {
      ini_set('zlib.output_compression', '0');
      ini_set('implicit_flush', '1');
      if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
      }
      ($callback)();
    };

    $response = new AiStreamedResponse($wrappedCallback, 200, [
      'Content-Type' => 'text/event-stream',
      'Connection' => 'keep-alive',
      'x-vercel-ai-ui-message-stream' => 'v1',
    ]);
    $this->sseResponse = $response;

    return $response;
  }

}
