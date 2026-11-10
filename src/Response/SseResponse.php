<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Response;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A streamed response that reaches the client one frame at a time.
 *
 * Symfony's own streamed response leaves PHP's output buffers alone, and
 * Drupal fills several of them. A callback that echoes a frame would wait in
 * the innermost buffer until it fills or the request ends, which delivers the
 * whole stream as one block. Server stacks buffer too, so the headers tell
 * nginx, Varnish and the FastCGI layer to pass the body through.
 *
 * A caller writes a frame with echo and flush(), and nothing else.
 */
class SseResponse extends StreamedResponse {

  /**
   * Class constructor.
   *
   * @param callable|null $callback
   *   Writes the frames of the stream.
   * @param int $status
   *   The HTTP status code.
   * @param array $headers
   *   Headers to add, which win over the ones this class sets.
   * @param string $terminator
   *   What to write once the callback has finished, for a protocol whose body
   *   ends with a sentinel the client waits for. Empty for a protocol with
   *   none.
   */
  public function __construct(?callable $callback = NULL, int $status = 200, array $headers = [], private readonly string $terminator = '') {
    parent::__construct($callback, $status, $headers + [
      'Content-Type' => 'text/event-stream',
      'Cache-Control' => 'no-cache',
      // Nginx and the FastCGI layer buffer a response body by default.
      'X-Accel-Buffering' => 'no',
      // Varnish and other caches in front of the site do the same.
      'Surrogate-Control' => 'no-store',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function sendContent(): static {
    // A stream lasts as long as the work behind it, which is longer than a
    // request is allowed to take.
    set_time_limit(0);

    // Compression needs the whole body before it can encode any of it, and
    // these three make PHP and Apache pass each frame through instead.
    ini_set('zlib.output_compression', '0');
    ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) {
      apache_setenv('no-gzip', '1');
    }

    // Close every buffer Drupal opened, so the callback's frames are not
    // held in one. Don't loop on ob_end_flush() to do it: PHP refuses to
    // remove some buffers, and the level then never drops. Symfony's helper
    // stops at the first buffer that refuses.
    static::closeOutputBuffers(0, TRUE);

    parent::sendContent();

    // A client's decoder reads to the end of the body, so a protocol whose
    // body ends with a sentinel leaves it waiting. Writing it here covers
    // every way the callback can finish, including the one that reports a
    // failure and returns.
    if ($this->terminator !== '') {
      echo $this->terminator;
      flush();
    }

    return $this;
  }

}
