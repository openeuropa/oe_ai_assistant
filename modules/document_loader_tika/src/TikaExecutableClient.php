<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\document_loader_tika\Exception\TikaException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Extracts document text by running the Apache Tika app JAR.
 */
final class TikaExecutableClient implements TikaClientInterface {

  private const float VERSION_TIMEOUT = 2.0;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function extract(string $path, string $accept = 'text/plain'): string {
    if (!is_file($path) || !is_readable($path)) {
      throw new TikaException(sprintf('The file %s could not be opened.', $path));
    }
    $command = $this->command($accept === 'text/html' ? '--xhtml' : '--text', $this->jarPath(), $path);
    try {
      $process = $this->run($command, $this->timeout());
    }
    catch (ProcessTimedOutException) {
      throw new TikaException('The Tika executable timed out while extracting the document.');
    }
    catch (\Throwable $e) {
      throw new TikaException('The Tika executable could not extract the document.', 0, $e);
    }
    if (!$process->isSuccessful()) {
      throw new TikaException('The Tika executable could not extract the document.');
    }
    $content = trim($process->getOutput());
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
      return $this->probe($this->jarPath());
    }
    catch (TikaException) {
      return NULL;
    }
  }

  /**
   * Returns the version reported by the JAR at the given path.
   *
   * Takes the path as an argument so a path that is not stored in
   * configuration yet can be checked, which is what the settings form needs.
   *
   * @throws \Drupal\document_loader_tika\Exception\TikaException
   *   When java is missing, the JAR cannot be read, or it reports no version.
   */
  public function probe(string $jar_path): string {
    $command = $this->command('--version', $jar_path);
    try {
      $process = $this->run($command, self::VERSION_TIMEOUT);
    }
    catch (\Throwable $e) {
      throw new TikaException('Java could not run the Tika app JAR.', 0, $e);
    }
    if (!$process->isSuccessful()) {
      throw new TikaException('Java could not run the Tika app JAR.');
    }
    $version = trim($process->getOutput());
    if ($version === '') {
      throw new TikaException('The Tika app JAR reported no version.');
    }

    return $version;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->version() !== NULL;
  }

  /**
   * Builds an argument-safe Java command for the given JAR.
   *
   * @return string[]
   *   The Java command and arguments.
   */
  private function command(string $command, string $jar_path, ?string $path = NULL): array {
    if (!is_file($jar_path) || !is_readable($jar_path)) {
      throw new TikaException('The Tika app JAR cannot be read.');
    }
    $java = (new ExecutableFinder())->find('java');
    if ($java === NULL) {
      throw new TikaException('The java executable was not found on PATH.');
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
   * Runs a Tika command with the configured timeout and UTF-8 environment.
   */
  private function run(array $command, float $timeout): Process {
    $process = new Process($command, NULL, [
      'LANG' => 'C.UTF-8',
      'LC_ALL' => 'C.UTF-8',
    ]);
    $process->setTimeout($timeout);
    $process->run();

    return $process;
  }

  /**
   * Reads the configured Tika app JAR path.
   */
  private function jarPath(): string {
    return (string) $this->configFactory->get('document_loader_tika.settings')->get('jar_path');
  }

  /**
   * Reads the configured extraction timeout in seconds.
   */
  private function timeout(): float {
    return (float) ($this->configFactory->get('document_loader_tika.settings')->get('timeout') ?? 30);
  }

}
