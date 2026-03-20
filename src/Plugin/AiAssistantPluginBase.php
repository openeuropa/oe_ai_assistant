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
 * Provides dispatch and helper methods. Concrete plugins must implement
 * getActionMap() to declare which URL actions are allowed and which
 * callables handle them.
 *
 * Action methods return an array (serialised to JSON by the controller)
 * or a Response (passed through as-is, e.g. for SSE streaming).
 * Errors are signalled by throwing ActionException.
 *
 * SSE streaming is handled via the swisnl/ag-ui-server package. Plugins
 * use AgUiState to emit typed AG-UI protocol events through a Drupal-
 * adapted SSE transporter.
 */
abstract class AiAssistantPluginBase extends PluginBase implements AiAssistantPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The AG-UI state manager for the current streaming response.
   *
   * Created lazily by createAgUiState() and used within the
   * createSseResponse() callback. Not available outside of a streaming
   * context.
   */
  protected ?AgUiState $agUiState = NULL;

  /**
   * The SSE transporter for the current streaming response.
   *
   * Stored separately so plugins can send events directly through the
   * transporter when AgUiState does not expose a method for a particular
   * event type (e.g., StateSnapshot).
   */
  protected ?DrupalSseTransporter $transporter = NULL;

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
   * Creates an AG-UI state manager for SSE streaming.
   *
   * Uses the DrupalSseTransporter which handles PascalCase-to-
   * SCREAMING_SNAKE_CASE type conversion and proxy padding automatically.
   * Each token is sent immediately without delta buffering so the
   * frontend receives a smooth, progressive stream.
   *
   * @return \Swis\AgUiServer\AgUiState
   *   The configured AG-UI state manager.
   */
  protected function createAgUiState(): AgUiState {
    $this->transporter = new DrupalSseTransporter();
    $this->transporter->initialize();

    $this->agUiState = new AgUiState($this->transporter);

    return $this->agUiState;
  }

  /**
   * Sends a STATE_SNAPSHOT event via the transporter.
   *
   * AgUiState does not expose a method for state snapshot events, so
   * this helper sends them directly through the transporter.
   *
   * @param array $snapshot
   *   The state snapshot data.
   */
  protected function sendStateSnapshot(array $snapshot): void {
    if ($this->transporter === NULL) {
      return;
    }
    $this->transporter->sendEvent(new StateSnapshotEvent($snapshot));
  }

  /**
   * Creates an SSE streaming response.
   *
   * Uses AiStreamedResponse from drupal/ai which handles output buffer
   * clearing and sets the correct headers (X-Accel-Buffering, Cache-Control,
   * Surrogate-Control) automatically.
   *
   * Additionally disables PHP and web server compression (gzip, zlib) and
   * enables implicit flush so that every echo reaches the client immediately
   * without being buffered for compression. Without these, environments with
   * gzip enabled at the PHP or web server layer will buffer the entire
   * response before sending, breaking SSE streaming.
   *
   * @param callable $callback
   *   The streaming callback. Will be executed after output buffers are
   *   cleared by AiStreamedResponse::sendContent().
   *
   * @return \Drupal\ai\Response\AiStreamedResponse
   *   The configured streaming response.
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
      'Content-Type' => 'text/event-stream',
      'Connection' => 'keep-alive',
    ]);
  }

}
