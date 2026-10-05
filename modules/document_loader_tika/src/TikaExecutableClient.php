<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\document_loader_tika\Exception\TikaException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Extracts document text by running the Apache Tika app JAR.
 */
final class TikaExecutableClient implements TikaClientInterface {

  private const float VERSION_TIMEOUT = 2.0;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TikaProcessRunnerInterface $processRunner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function extract(string $path, string $accept = 'text/plain'): string {
    if (!is_file($path) || !is_readable($path)) {
      throw new TikaException(sprintf('The file %s could not be opened.', $path));
    }
    $result = $this->processRunner->run(
      $this->command($accept === 'text/html' ? '--xhtml' : '--text', $path),
      $this->timeout(),
      $this->environment(),
    );
    if ($result->timedOut) {
      throw new TikaException('The Tika executable timed out while extracting the document.');
    }
    if (!$result->successful) {
      throw new TikaException('The Tika executable could not extract the document.');
    }
    $content = trim($result->output);
    if ($content === '') {
      throw new TikaException('The Tika executable returned no text for the document.');
    }

    return $content;
  }

  /**
   * {@inheritdoc}
   */
  public function version(): ?string {
    try {
      $result = $this->processRunner->run(
        $this->command('--version'),
        self::VERSION_TIMEOUT,
        $this->environment(),
      );
    }
    catch (TikaException) {
      return NULL;
    }
    if (!$result->successful || $result->timedOut) {
      return NULL;
    }
    $version = trim($result->output);

    return $version === '' ? NULL : $version;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->version() !== NULL;
  }

  /**
   * Builds an argument-safe Java command for the configured JAR.
   *
   * @return string[]
   *   The Java command and arguments.
   */
  private function command(string $command, ?string $path = NULL): array {
    $java = (new ExecutableFinder())->find('java');
    if ($java === NULL) {
      throw new TikaException('The java executable was not found on PATH.');
    }
    $jar_path = (string) $this->configFactory->get('document_loader_tika.settings')->get('jar_path');
    if (!is_file($jar_path) || !is_readable($jar_path)) {
      throw new TikaException('The configured Tika app JAR cannot be read.');
    }
    $arguments = [
      $java,
      '-Djava.awt.headless=true',
      '-Dfile.encoding=UTF-8',
      '-jar',
      $jar_path,
      $command,
    ];
    if ($path !== NULL) {
      $arguments[] = $path;
    }

    return $arguments;
  }

  /**
   * Gets the UTF-8 process environment required for file paths.
   *
   * @return array<string, string>
   *   The environment variables passed to the Java process.
   */
  private function environment(): array {
    return [
      'LANG' => 'C.UTF-8',
      'LC_ALL' => 'C.UTF-8',
    ];
  }

  /**
   * Reads the configured extraction timeout in seconds.
   */
  private function timeout(): float {
    return (float) ($this->configFactory->get('document_loader_tika.settings')->get('timeout') ?? 30);
  }

}
