<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Streaming;

/**
 * Writes UI Message Stream Protocol events as SSE lines.
 *
 * Each event is a JSON object with a "type" discriminator,
 * sent as a standard SSE "data:" line followed by a blank line.
 * This replaces the AG-UI state machine and transporter with a
 * single emit method.
 *
 * Wire format per event:
 *   data: {"type":"text-delta","id":"t1","delta":"Hello"}\n\n
 *
 * Protocol: Vercel AI SDK UI Message Stream v1.
 *
 * @see https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
 */
class DataStreamWriter {

  /**
   * Emits a single SSE event to the output buffer.
   *
   * The event is JSON-encoded with the given type as the
   * discriminator field, then written as an SSE "data:" line
   * and flushed immediately to the client.
   *
   * @param string $type
   *   The event type (e.g. 'text-delta', 'tool-input-start',
   *   'data-drafted-fields').
   * @param array<string, mixed> $data
   *   Additional event payload fields merged with the type.
   */
  public function emit(string $type, array $data = []): void {
    $data['type'] = $type;
    echo 'data: ' . json_encode(
      $data,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    ) . "\n\n";
    if (ob_get_level() > 0) {
      @ob_flush();
    }
    flush();
  }

  /**
   * Sends the stream termination signal.
   *
   * The [DONE] sentinel tells the client that no more events
   * will follow and the SSE connection can be closed.
   */
  public function done(): void {
    echo "data: [DONE]\n\n";
    if (ob_get_level() > 0) {
      @ob_flush();
    }
    flush();
  }

}
