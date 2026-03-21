<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Transporter;

use Swis\AgUiServer\Events\AgUiEvent;
use Swis\AgUiServer\Transporter\AbstractTransporter;

/**
 * SSE transporter adapted for the Drupal streaming environment.
 *
 * This transporter is designed to work inside an AiStreamedResponse
 * callback where headers have already been sent by the response
 * object. It does NOT call header() directly (unlike the upstream
 * SseTransporter).
 *
 * It converts event type strings from PascalCase (as emitted by the
 * swisnl/ag-ui-server package) to SCREAMING_SNAKE_CASE (as expected
 * by the AG-UI TypeScript SDK and our React frontend). For example,
 * "RunStarted" becomes "RUN_STARTED", "TextMessageContent" becomes
 * "TEXT_MESSAGE_CONTENT".
 */
class DrupalSseTransporter extends AbstractTransporter {

  /**
   * Whether the proxy padding comment has been sent.
   *
   * The first output on the stream must include padding to force
   * proxy buffers (nginx/FastCGI) to flush immediately. Proxies
   * typically buffer 4-8 KB of data before forwarding it to the
   * client. Without this padding, the initial events would be held
   * back until the buffer fills naturally, causing a visible delay
   * at the start of the stream.
   *
   * @var bool
   */
  private bool $paddingSent = FALSE;

  /**
   * {@inheritdoc}
   *
   * No-op: headers are set by AiStreamedResponse, not by the
   * transporter. The response object already writes the required
   * Content-Type: text/event-stream and Cache-Control headers and
   * disables output buffering before this class is invoked.
   */
  public function initialize(): void {
    // Headers are handled by createSseResponse() in the plugin base
    // class. Output buffer clearing and compression disabling are
    // also handled there. This method intentionally does nothing.
  }

  /**
   * {@inheritdoc}
   *
   * Sends an AG-UI event as a single SSE "data:" line. The event
   * data is serialised to JSON after normalising the type and
   * timestamp fields. A 4 KB proxy-flush padding comment is prepended
   * before the very first event.
   *
   * @param \Swis\AgUiServer\Events\AgUiEvent $event
   *   The AG-UI event to serialise and transmit.
   *
   * @throws \JsonException
   *   If JSON serialisation of the event data fails.
   */
  protected function doSend(AgUiEvent $event): void {
    // Send proxy padding on the first event to overcome nginx/FastCGI
    // buffering. Proxies typically buffer 4-8 KB before flushing;
    // this comment fills that quota immediately so the client starts
    // receiving events without delay.
    if (!$this->paddingSent) {
      $this->sendComment(str_repeat(' ', 4096));
      $this->paddingSent = TRUE;
    }

    // Retrieve the event's serialisable array representation and
    // normalise the "type" field for the AG-UI TypeScript SDK, which
    // requires SCREAMING_SNAKE_CASE rather than PascalCase.
    $data = $event->toArray();
    $data['type'] = self::toScreamingSnake($data['type'] ?? '');

    // The swisnl package emits timestamp as an RFC 3339 string, but
    // the AG-UI TypeScript SDK validates it as z.number().optional().
    // Convert to a Unix epoch integer in milliseconds to satisfy the
    // SDK's Zod schema. Fall back to the current time if strtotime()
    // cannot parse the value (should never occur in practice).
    if (isset($data['timestamp'])) {
      $ts = strtotime($data['timestamp']);
      $data['timestamp'] = $ts !== FALSE ? $ts * 1000 : (int) (microtime(TRUE) * 1000);
    }

    // Encode the event data as JSON and write it as an SSE data line.
    // The double newline (\n\n) terminates the SSE event block and
    // signals the client that the event is complete.
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    echo "data: {$json}\n\n";

    // Push the data out of PHP's output buffer and through the web
    // server to the client immediately after each event.
    $this->flush();
  }

  /**
   * {@inheritdoc}
   *
   * Writes an SSE comment line to the output stream and flushes.
   *
   * SSE comment lines begin with a colon and are not dispatched as
   * events to JavaScript EventSource listeners, but they are sent
   * over the wire. They are used here for proxy padding and keepalive
   * pings.
   *
   * @param string $comment
   *   The comment text to send (without the leading colon or newlines).
   */
  public function sendComment(string $comment): void {
    // Write an SSE comment line. The double newline terminates the
    // block so browsers and proxies treat it as a complete SSE frame.
    echo ": {$comment}\n\n";
    $this->flush();
  }

  /**
   * {@inheritdoc}
   *
   * Called by the AG-UI server loop when the stream is finished.
   * Issues a final flush to ensure any buffered data reaches the
   * client before the response is closed.
   */
  public function close(): void {
    $this->flush();
  }

  /**
   * Flushes all output buffers to ensure data reaches the client.
   *
   * Drupal or PHP may recreate output buffers after AiStreamedResponse
   * clears them (e.g. error handlers or other middleware may open new
   * buffers). Both ob_flush() and flush() are needed: ob_flush()
   * pushes data out of PHP's output buffer into the SAPI layer,
   * and flush() instructs the SAPI (FPM / FastCGI) to forward that
   * data to the web server (nginx).
   *
   * The @ suppressor on ob_flush() is intentional: if no output
   * buffer is active, ob_flush() emits an E_NOTICE, which is
   * harmless but noisy. The ob_get_level() guard already makes the
   * call conditional, but the suppressor adds extra safety for
   * nested buffer scenarios.
   */
  private function flush(): void {
    // Flush PHP's userspace output buffer if one is open.
    if (ob_get_level() > 0) {
      @ob_flush();
    }
    // Flush the SAPI layer buffer to the web server / network.
    flush();
  }

  /**
   * Converts a PascalCase string to SCREAMING_SNAKE_CASE.
   *
   * The swisnl/ag-ui-server package emits event type strings in
   * PascalCase (e.g. "RunStarted", "TextMessageContent"), but the
   * AG-UI protocol TypeScript SDK and our React frontend expect
   * SCREAMING_SNAKE_CASE (e.g. "RUN_STARTED", "TEXT_MESSAGE_CONTENT").
   *
   * The conversion is performed in two regex passes to handle both
   * common PascalCase transitions:
   *   - Pass 1: lowercase-to-uppercase boundary (e.g. "nS" -> "n_S").
   *   - Pass 2: consecutive-uppercase-to-mixed boundary (e.g. "LSt"
   *     -> "L_St"), which handles acronyms like "URLString" correctly.
   *
   * Examples:
   *   - "RunStarted"        -> "RUN_STARTED"
   *   - "TextMessageContent" -> "TEXT_MESSAGE_CONTENT"
   *   - "URLString"         -> "URL_STRING"
   *
   * @param string $input
   *   The PascalCase string to convert.
   *
   * @return string
   *   The resulting SCREAMING_SNAKE_CASE string.
   */
  private static function toScreamingSnake(string $input): string {
    // Pass 1: insert an underscore between a lowercase letter and the
    // following uppercase letter (e.g. "nS" -> "n_S"). This handles
    // the most common word boundary in PascalCase strings.
    $snake = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);

    // Pass 2: insert an underscore between a run of uppercase letters
    // and an uppercase letter followed by a lowercase letter
    // (e.g. "LSt" -> "L_St"). This correctly splits acronyms that
    // precede a capitalised word (e.g. "HTMLParser" -> "HTML_Parser").
    $snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake);

    // Uppercase the entire result to produce SCREAMING_SNAKE_CASE.
    return strtoupper($snake);
  }

}
