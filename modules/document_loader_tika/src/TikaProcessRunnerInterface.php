<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

/**
 * Runs the Tika Java process.
 */
interface TikaProcessRunnerInterface {

  /**
   * Runs a command without passing it through a shell.
   *
   * @param string[] $command
   *   The command and its arguments.
   * @param float $timeout
   *   Seconds allowed for the process.
   * @param array<string, string> $environment
   *   Environment variables for the process.
   */
  public function run(array $command, float $timeout, array $environment): TikaProcessResult;

}
