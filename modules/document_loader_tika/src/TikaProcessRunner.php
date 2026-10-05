<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Symfony Process implementation for the executable Tika client.
 */
final class TikaProcessRunner implements TikaProcessRunnerInterface {

  /**
   * {@inheritdoc}
   */
  public function run(array $command, float $timeout, array $environment): TikaProcessResult {
    $process = new Process($command, NULL, $environment);
    $process->setTimeout($timeout);
    try {
      $process->run();
    }
    catch (ProcessTimedOutException) {
      return new TikaProcessResult(FALSE, TRUE, $process->getOutput(), $process->getErrorOutput());
    }
    catch (\Throwable $e) {
      return new TikaProcessResult(FALSE, FALSE, '', $e->getMessage());
    }

    return new TikaProcessResult($process->isSuccessful(), FALSE, $process->getOutput(), $process->getErrorOutput());
  }

}
