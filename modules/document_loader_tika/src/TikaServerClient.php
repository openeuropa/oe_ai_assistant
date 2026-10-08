<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\document_loader_tika\Exception\TikaException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Guzzle-based client for an Apache Tika REST server.
 */
final class TikaServerClient implements TikaClientInterface {

  private const float VERSION_TIMEOUT = 2.0;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function extract(string $path, string $accept = 'text/plain'): string {
    $stream = @fopen($path, 'rb');
    if ($stream === FALSE) {
      throw new TikaException(sprintf('The file %s could not be opened.', $path));
    }
    try {
      $response = $this->httpClient->request('PUT', $this->url('/tika'), [
        'body' => $stream,
        'headers' => ['Accept' => $accept],
        'timeout' => $this->timeout(),
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new TikaException('The Tika server could not be reached: ' . $e->getMessage(), 0, $e);
    }
    if ($response->getStatusCode() !== 200) {
      throw new TikaException(sprintf('The Tika server answered with status %d.', $response->getStatusCode()));
    }
    $content = trim((string) $response->getBody());
    if ($content === '') {
      throw new TikaException('The Tika server returned no text for the document.');
    }

    return $content;
  }

  /**
   * {@inheritdoc}
   */
  public function version(): ?string {
    try {
      $response = $this->httpClient->request('GET', $this->url('/version'), [
        'timeout' => self::VERSION_TIMEOUT,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException) {
      return NULL;
    }
    if ($response->getStatusCode() !== 200) {
      return NULL;
    }
    $version = trim((string) $response->getBody());

    return $version === '' ? NULL : $version;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->version() !== NULL;
  }

  /**
   * Builds an endpoint URL from the configured server URL.
   */
  private function url(string $endpoint): string {
    $base = (string) $this->configFactory->get('document_loader_tika.settings')->get('url');

    return rtrim($base, '/') . $endpoint;
  }

  /**
   * Reads the configured extraction timeout in seconds.
   */
  private function timeout(): float {
    return (float) ($this->configFactory->get('document_loader_tika.settings')->get('timeout') ?? 30);
  }

}
