<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Http;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Psr\Http\Message\RequestInterface;

/**
 * Writes every outgoing HTTP request to a file, one file per request.
 *
 * Drupal builds the Guzzle client of every drupal/ai provider from the
 * shared handler stack, so this middleware sees the provider call as the
 * SDK built it: the request line, the headers and the body bytes. The file
 * is a replayable HTTP request, which is what a backend maintainer needs to
 * reproduce a rejected call.
 *
 * Off unless settings.php switches it on:
 * @code
 * $settings['oe_ai_assistant_log_requests'] = TRUE;
 * @endcode
 */
final class ProviderCallLogger {

  /**
   * Header values never written to disk, lower case.
   */
  private const SECRET_HEADERS = [
    'authorization',
    'api-key',
    'x-api-key',
    'openai-api-key',
    'cookie',
    'proxy-authorization',
  ];

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Returns the middleware that logs the request and passes it on.
   */
  public function __invoke(): callable {
    return function (callable $handler): callable {
      return function (RequestInterface $request, array $options) use ($handler) {
        if (Settings::get('oe_ai_assistant_log_requests', FALSE)) {
          $this->write($request);
        }
        return $handler($request, $options);
      };
    };
  }

  /**
   * Writes one request to its own file, named after the moment it was sent.
   */
  private function write(RequestInterface $request): void {
    $directory = 'private://ai-calls';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      return;
    }

    $moment = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(TRUE)));
    $name = sprintf(
      '%s_%s.http',
      $moment->format('Y-m-d_H-i-s.u'),
      $request->getUri()->getHost(),
    );

    @file_put_contents($this->fileSystem->realpath($directory) . '/' . $name, $this->dump($request));
  }

  /**
   * Renders a request the way it travels, with the secrets taken out.
   */
  private function dump(RequestInterface $request): string {
    $uri = $request->getUri();
    $target = $request->getRequestTarget();
    $lines = [sprintf('%s %s HTTP/%s', $request->getMethod(), $target, $request->getProtocolVersion())];

    foreach ($request->getHeaders() as $header => $values) {
      $secret = in_array(strtolower($header), self::SECRET_HEADERS, TRUE);
      $lines[] = $header . ': ' . ($secret ? '[redacted]' : implode(', ', $values));
    }
    if (!$request->hasHeader('Host')) {
      array_splice($lines, 1, 0, ['Host: ' . $uri->getHost()]);
    }

    return implode("\n", $lines) . "\n\n" . $this->body($request) . "\n";
  }

  /**
   * Reads the request body without spending it, empty when it cannot rewind.
   *
   * A JSON body is indented so the payload can be read, which changes its
   * whitespace. Content-Length in the headers above measures the bytes that
   * were sent.
   */
  private function body(RequestInterface $request): string {
    $body = $request->getBody();
    if ($body->getSize() === 0) {
      return '';
    }
    if (!$body->isSeekable()) {
      return '[body not readable: the stream cannot be rewound]';
    }
    $body->rewind();
    $contents = $body->getContents();
    $body->rewind();

    $decoded = json_decode($contents, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return $contents;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

}
