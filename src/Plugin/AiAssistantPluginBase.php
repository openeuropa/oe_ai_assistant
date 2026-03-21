<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\ai\Response\AiStreamedResponse;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter;
use Swis\AgUiServer\AgUiState;
use Swis\AgUiServer\Events\StateSnapshotEvent;
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
 * SSE (Server-Sent Events) streaming relies on two collaborators:
 *   - DrupalSseTransporter: a Drupal-adapted SSE transport that converts
 *     AG-UI event class names from PascalCase to SCREAMING_SNAKE_CASE and
 *     sends each event as a properly formatted SSE message. It also writes
 *     an initial padding comment so that proxy servers that buffer small
 *     responses flush immediately.
 *   - AgUiState (swisnl/ag-ui-server): an AG-UI protocol state machine that
 *     emits typed events (RunStarted, TextMessageStart, TextMessageContent,
 *     TextMessageEnd, RunFinished, etc.) through the transporter.
 *
 * Both objects are stored as nullable properties so the class stays usable
 * in non-streaming contexts. They are populated by createAgUiState() and
 * should only be referenced inside the callback passed to createSseResponse().
 *
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginInterface
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager
 * @see \Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter
 */
abstract class AiAssistantPluginBase extends PluginBase implements AiAssistantPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The AG-UI state manager for the current streaming response.
   *
   * Created lazily by createAgUiState() and consumed within the callback
   * passed to createSseResponse(). NULL outside of an active SSE stream.
   *
   * AgUiState drives the AG-UI protocol event lifecycle: it tracks whether
   * a run has started, manages message IDs, and delegates the actual
   * network writes to the injected transporter.
   *
   * @var \Swis\AgUiServer\AgUiState|null
   */
  protected ?AgUiState $agUiState = NULL;

  /**
   * The SSE transporter for the current streaming response.
   *
   * Stored as a class property (rather than a local variable) so that
   * plugins can call sendStateSnapshot() directly when AgUiState does not
   * expose a method for a particular event type (e.g., StateSnapshot, which
   * carries the initial field values snapshot to the frontend).
   *
   * NULL outside of an active SSE stream.
   *
   * @var \Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter|null
   */
  protected ?DrupalSseTransporter $transporter = NULL;

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
   * Creates an AG-UI state manager and its underlying SSE transporter.
   *
   * This method must be called once at the start of any streaming action
   * before invoking AgUiState methods or sendStateSnapshot(). It initialises
   * the DrupalSseTransporter (which sends the HTTP headers and the proxy-
   * padding comment) and wires it into a fresh AgUiState instance.
   *
   * DrupalSseTransporter responsibilities:
   *   - Converts AG-UI event class names from PascalCase to
   *     SCREAMING_SNAKE_CASE so that the frontend receives the canonical
   *     AG-UI event type strings (e.g., "TEXT_MESSAGE_CONTENT").
   *   - Sends a proxy-padding comment at the start of the stream so that
   *     HTTP proxies that buffer small responses flush immediately.
   *   - Writes each event as a properly formatted "data: ...\n\n" SSE line.
   *
   * @return \Swis\AgUiServer\AgUiState
   *   The configured AG-UI state manager, also stored in $this->agUiState.
   */
  protected function createAgUiState(): AgUiState {
    // Instantiate and initialise the transporter. initialize() sends the
    // SSE headers and the proxy-padding comment so these arrive before any
    // AG-UI events.
    $this->transporter = new DrupalSseTransporter();
    $this->transporter->initialize();

    // Wire the transporter into AgUiState. All subsequent state-machine
    // method calls (startRun, startTextMessage, etc.) will delegate their
    // network writes to this transporter.
    $this->agUiState = new AgUiState($this->transporter);

    return $this->agUiState;
  }

  /**
   * Sends a STATE_SNAPSHOT event directly through the SSE transporter.
   *
   * The AG-UI protocol defines a StateSnapshot event that carries a
   * key/value map of UI state to the frontend (for example, the initial set
   * of field values before streaming begins). The swisnl/ag-ui-server
   * AgUiState class does not expose a dedicated method for this event type,
   * so this helper bypasses AgUiState and writes the event directly through
   * the transporter.
   *
   * If the transporter has not been initialised yet (i.e., createAgUiState()
   * was never called), this method returns silently to prevent fatal errors
   * in degraded code paths.
   *
   * @param array<string, mixed> $snapshot
   *   Arbitrary key/value state data to include in the event payload. The
   *   keys and value shapes are defined by the frontend plugin that consumes
   *   the snapshot.
   */
  protected function sendStateSnapshot(array $snapshot): void {
    // Bail out if streaming was never initialised. This guards against
    // accidental calls from non-streaming action methods.
    if ($this->transporter === NULL) {
      return;
    }
    $this->transporter->sendEvent(new StateSnapshotEvent($snapshot));
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
   *   createAgUiState() internally and use AgUiState to emit events.
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
    ]);
  }

}
