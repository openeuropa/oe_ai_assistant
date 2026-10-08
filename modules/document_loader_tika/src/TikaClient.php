<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\document_loader_tika\Exception\TikaException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Selects the configured Tika extraction source.
 */
final class TikaClient implements TikaClientInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TikaServerClient $serverClient,
    private readonly TikaExecutableClient $executableClient,
    #[Autowire(service: 'logger.channel.document_loader_tika')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function extract(string $path, string $accept = 'text/plain'): string {
    $mode = $this->mode();
    $this->logger->info('Tika extraction started: @mode mode, @path.', [
      '@mode' => $mode,
      '@path' => $path,
    ]);
    $started = microtime(TRUE);

    try {
      $content = $this->client()->extract($path, $accept);
    }
    catch (TikaException $e) {
      $this->logger->error('Tika extraction failed: @mode mode, @path, after @seconds seconds: @message', [
        '@mode' => $mode,
        '@path' => $path,
        '@seconds' => $this->elapsed($started),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }

    $this->logger->info('Tika extraction succeeded: @mode mode, @path, @characters characters in @seconds seconds.', [
      '@mode' => $mode,
      '@path' => $path,
      '@characters' => mb_strlen($content),
      '@seconds' => $this->elapsed($started),
    ]);

    return $content;
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
    return $this->mode() === 'executable' ? $this->executableClient : $this->serverClient;
  }

  /**
   * Reads the configured extraction mode.
   */
  private function mode(): string {
    return (string) $this->configFactory->get('document_loader_tika.settings')->get('mode');
  }

  /**
   * Measures the seconds spent since a start time, for the log messages.
   */
  private function elapsed(float $started): string {
    return number_format(microtime(TRUE) - $started, 2);
  }

}
