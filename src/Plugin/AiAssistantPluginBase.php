<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\ai\Response\AiStreamedResponse;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\oe_ai_assistant\Streaming\DataStreamWriter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
 * SSE (Server-Sent Events) streaming uses the DataStreamWriter, which emits
 * UI Message Stream Protocol v1 events as SSE "data:" lines. Each event is
 * a JSON object with a "type" discriminator field. The writer is created by
 * createWriter() and stored as a nullable property so the class stays usable
 * in non-streaming contexts.
 *
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginInterface
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager
 * @see \Drupal\oe_ai_assistant\Streaming\DataStreamWriter
 */
abstract class AiAssistantPluginBase extends PluginBase implements AiAssistantPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The DataStreamWriter for the current streaming response.
   *
   * Created by createWriter() and consumed within the callback passed to
   * createSseResponse(). NULL outside of an active SSE stream.
   *
   * @var \Drupal\oe_ai_assistant\Streaming\DataStreamWriter|null
   */
  protected ?DataStreamWriter $writer = NULL;

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
   * Creates a DataStreamWriter for the current streaming response.
   *
   * This method must be called once at the start of any streaming action
   * before emitting events. The writer is stored in $this->writer so that
   * subclasses can access it during the streaming callback.
   *
   * @return \Drupal\oe_ai_assistant\Streaming\DataStreamWriter
   *   The writer instance, also stored in $this->writer.
   */
  protected function createWriter(): DataStreamWriter {
    $this->writer = new DataStreamWriter();
    return $this->writer;
  }

  /**
   * Creates and returns a Symfony Response configured for SSE streaming.
   *
   * SSE (Server-Sent Events) requires the response to remain open while the
   * server pushes data. This method uses AiStreamedResponse from drupal/ai,
   * which:
   *   - Clears all existing PHP output buffers before invoking the callback,
   *     preventing previously buffered content from prepending the SSE stream.
   *   - Sets X-Accel-Buffering: no so that nginx does not buffer the body.
   *   - Sets Cache-Control and Surrogate-Control headers to prevent CDN
   *     caching of the event stream.
   *
   * This method wraps the caller's callback in an outer closure that applies
   * additional anti-buffering measures before delegating to the caller:
   *   - zlib.output_compression: disables PHP-level gzip compression, which
   *     would accumulate the entire stream before compressing and sending it.
   *   - implicit_flush: makes PHP flush after every output statement so that
   *     each SSE event reaches the client as soon as it is written.
   *   - Apache no-gzip: disables mod_deflate when running behind Apache.
   *     This has no effect on nginx or other web servers.
   *
   * Without these measures, environments with gzip enabled at the PHP or
   * web-server layer will buffer the entire response body, causing all SSE
   * events to arrive together at the end instead of progressively.
   *
   * @param callable $callback
   *   The streaming callback containing the plugin's SSE logic. It will be
   *   executed after output buffers have been cleared by
   *   AiStreamedResponse::sendContent(). The callback should call
   *   createWriter() internally and use DataStreamWriter to emit events.
   *
   * @return \Drupal\ai\Response\AiStreamedResponse
   *   A streaming response with Content-Type text/event-stream and
   *   Connection keep-alive headers set.
   */
  protected function createSseResponse(callable $callback): AiStreamedResponse {
    // Wrap the callback to disable compression and enable implicit flush
    // before the plugin's streaming logic runs. This ensures SSE data is
    // never held up by gzip or output buffering in any environment.
    $wrappedCallback = function () use ($callback) {
      // Disable PHP-level gzip compression which would buffer the
      // entire response body before sending.
      ini_set('zlib.output_compression', '0');

      // Auto-flush after every echo so events are sent immediately
      // even if an explicit flush() call is missed.
      ini_set('implicit_flush', '1');

      // Disable Apache mod_deflate compression when running behind
      // Apache. Has no effect on nginx or other web servers.
      if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
      }

      ($callback)();
    };

    return new AiStreamedResponse($wrappedCallback, 200, [
      // Required MIME type for the SSE protocol; tells the browser to
      // treat the response as an event stream and parse it accordingly.
      'Content-Type' => 'text/event-stream',
      // Instructs HTTP/1.1 clients and intermediaries to keep the TCP
      // connection open for the duration of the stream.
      'Connection' => 'keep-alive',
      // Identifies the stream as UI Message Stream Protocol v1 so the
      // Vercel AI SDK frontend parser selects the correct decoder.
      'x-vercel-ai-ui-message-stream' => 'v1',
    ]);
  }

}
