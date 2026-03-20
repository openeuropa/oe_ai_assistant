<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Transporter;

use Swis\AgUiServer\Events\AgUiEvent;
use Swis\AgUiServer\Transporter\AbstractTransporter;

/**
 * SSE transporter adapted for the Drupal streaming environment.
 *
 * This transporter is designed to work inside an AiStreamedResponse callback
 * where headers have already been sent by the response object. It does NOT
 * call header() directly (unlike the upstream SseTransporter).
 *
 * It converts event type strings from PascalCase (as emitted by the
 * swisnl/ag-ui-server package) to SCREAMING_SNAKE_CASE (as expected by the
 * AG-UI TypeScript SDK and our React frontend). For example, "RunStarted"
 * becomes "RUN_STARTED", "TextMessageContent" becomes "TEXT_MESSAGE_CONTENT".
 */
class DrupalSseTransporter extends AbstractTransporter {

  /**
   * Whether the proxy padding comment has been sent.
   *
   * The first output on the stream must include padding to force proxy
   * buffers (nginx/FastCGI) to flush immediately.
   */
  private bool $paddingSent = FALSE;

  /**
   * {@inheritdoc}
   *
   * No-op: headers are set by AiStreamedResponse, not by the transporter.
   */
  public function initialize(): void {
    // Headers are handled by createSseResponse() in the plugin base class.
    // Output buffer clearing and compression disabling are also handled
    // there. This method intentionally does nothing.
  }

  /**
   * {@inheritdoc}
   *
   * Sends an AG-UI event as an SSE data line. Converts the event type
   * from PascalCase to SCREAMING_SNAKE_CASE before encoding. Prepends
   * 4 KB of proxy padding before the first event.
   */
  protected function doSend(AgUiEvent $event): void {
    // Send proxy padding on the first event to overcome nginx/FastCGI
    // buffering. Proxies typically buffer 4-8 KB before flushing.
    if (!$this->paddingSent) {
      $this->sendComment(str_repeat(' ', 4096));
      $this->paddingSent = TRUE;
    }

    // Get the event data array and convert the type string.
    $data = $event->toArray();
    $data['type'] = self::toScreamingSnake($data['type'] ?? '');

    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    echo "data: {$json}\n\n";

    $this->flush();
  }

  /**
   * {@inheritdoc}
   */
  public function sendComment(string $comment): void {
    echo ": {$comment}\n\n";
    $this->flush();
  }

  /**
   * {@inheritdoc}
   */
  public function close(): void {
    $this->flush();
  }

  /**
   * Flushes all output buffers to ensure data reaches the client.
   *
   * Drupal or PHP may recreate output buffers after AiStreamedResponse
   * clears them. Both ob_flush() and flush() are needed: ob_flush()
   * pushes data out of PHP's output buffer, flush() pushes it to the
   * web server.
   */
  private function flush(): void {
    if (ob_get_level() > 0) {
      @ob_flush();
    }
    flush();
  }

  /**
   * Converts a PascalCase string to SCREAMING_SNAKE_CASE.
   *
   * The swisnl/ag-ui-server package uses PascalCase event type strings
   * (e.g., "RunStarted", "TextMessageContent"), but the AG-UI protocol
   * TypeScript SDK and our React frontend expect SCREAMING_SNAKE_CASE
   * (e.g., "RUN_STARTED", "TEXT_MESSAGE_CONTENT").
   *
   * @param string $input
   *   The PascalCase string to convert.
   *
   * @return string
   *   The SCREAMING_SNAKE_CASE string.
   */
  private static function toScreamingSnake(string $input): string {
    // Insert underscores before uppercase letters that follow a lowercase
    // letter or another uppercase letter followed by a lowercase letter.
    $snake = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);
    $snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake);
    return strtoupper($snake);
  }

}
