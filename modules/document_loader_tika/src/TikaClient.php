<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Selects the configured Tika extraction source.
 */
final class TikaClient implements TikaClientInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TikaServerClient $serverClient,
    private readonly TikaExecutableClient $executableClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function extract(string $path, string $accept = 'text/plain'): string {
    return $this->client()->extract($path, $accept);
  }

  /**
   * {@inheritdoc}
   */
  public function version(): ?string {
    return $this->client()->version();
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->client()->isAvailable();
  }

  /**
   * Gets the client for the active extraction mode.
   */
  public function client(): TikaClientInterface {
    $mode = $this->configFactory->get('document_loader_tika.settings')->get('mode');

    return $mode === 'executable' ? $this->executableClient : $this->serverClient;
  }

}
