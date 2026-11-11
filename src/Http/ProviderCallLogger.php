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

  /**
   * Query parameters and body keys whose value is redacted, lower case.
   */
  private const SECRET_KEYS = [
    'api_key',
    'api-key',
    'apikey',
    'key',
    'access_token',
    'accesstoken',
    'token',
    'client_secret',
    'secret',
    'password',
    'passwd',
    'subscription-key',
    'signature',
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
    $target = $this->target($request->getRequestTarget());
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
   * Hides the value of any secret the request carries in its query string.
   *
   * Several providers take the API key as a query parameter rather than a
   * header, so redacting headers alone would still write the key to disk.
   * We rewrite only the values. The file is replayed as it stands, so the
   * rest of the request line stays byte for byte what was sent.
   *
   * @param string $target
   *   The request target, path and query.
   *
   * @return string
   *   The target, with every secret value replaced.
   */
  private function target(string $target): string {
    return (string) preg_replace_callback(
      '@(?<=[?&])([^=&]+)=([^&]*)@',
      static fn (array $match): string => in_array(strtolower(urldecode($match[1])), self::SECRET_KEYS, TRUE)
        ? $match[1] . '=[redacted]'
        : $match[0],
      $target,
    );
  }

  /**
   * Hides the value of any secret the decoded body carries, at any depth.
   *
   * @param mixed $value
   *   A decoded JSON value.
   *
   * @return mixed
   *   The value, with every secret replaced.
   */
  private function redact(mixed $value): mixed {
    if (is_object($value)) {
      foreach (get_object_vars($value) as $name => $nested) {
        $value->{$name} = in_array(strtolower($name), self::SECRET_KEYS, TRUE)
          ? '[redacted]'
          : $this->redact($nested);
      }

      return $value;
    }

    return is_array($value) ? array_map($this->redact(...), $value) : $value;
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

    // Decode to objects, not arrays. Passing TRUE here turns an empty JSON
    // object into an empty PHP array, which encodes again as [], so a file
    // meant to be replayed would claim we sent "parameters": [] where we
    // sent "parameters": {}.
    $decoded = json_decode($contents);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return $contents;
    }

    return (string) json_encode(
      $this->redact($decoded),
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
  }

}
