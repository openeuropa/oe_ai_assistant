<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Transporter;

use Swis\AgUiServer\Events\AgUiEvent;
use Swis\AgUiServer\Transporter\SseTransporter;

/**
 * SSE transporter adapted for the Drupal streaming environment.
 */
class DrupalSseTransporter extends SseTransporter {

  /**
   * {@inheritdoc}
   *
   * Sends an AG-UI event as a single SSE "data:" line. The event
   * data is serialised to JSON after normalising the type and
   * timestamp fields.
   */
  protected function doSend(AgUiEvent $event): void {
    // Retrieve the event's serialisable array representation and
    // normalise the "type" field for the AG-UI TypeScript SDK,
    // which requires SCREAMING_SNAKE_CASE rather than PascalCase.
    $data = $event->toArray();
    $data['type'] = self::toScreamingSnake($data['type'] ?? '');

    // The swisnl package emits timestamp as an RFC 3339 string,
    // but the AG-UI TypeScript SDK validates it as
    // z.number().optional(). Convert to a Unix epoch integer in
    // milliseconds to satisfy the SDK's Zod schema.
    if (isset($data['timestamp'])) {
      $ts = strtotime($data['timestamp']);
      $data['timestamp'] = $ts !== FALSE
        ? $ts * 1000
        : (int) (microtime(TRUE) * 1000);
    }

    // Encode the event data as JSON and write it as an SSE data
    // line. The double newline terminates the SSE event block.
    $json = json_encode(
      $data,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );
    echo "data: $json\n\n";

    // Push the data out of PHP's output buffer and through the
    // web server to the client immediately after each event.
    if (ob_get_level() > 0) {
      @ob_flush();
    }
    flush();
  }

  /**
   * Converts a PascalCase string to SCREAMING_SNAKE_CASE.
   *
   * Examples:
   *   - "RunStarted"         -> "RUN_STARTED"
   *   - "TextMessageContent" -> "TEXT_MESSAGE_CONTENT"
   *   - "URLString"          -> "URL_STRING"
   *
   * @param string $input
   *   The PascalCase string to convert.
   *
   * @return string
   *   The resulting SCREAMING_SNAKE_CASE string.
   */
  private static function toScreamingSnake(string $input): string {
    $snake = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);
    $snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake);
    return strtoupper($snake);
  }

}
