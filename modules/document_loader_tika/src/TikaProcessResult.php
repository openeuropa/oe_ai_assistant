<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

/**
 * The result of an executable Tika process.
 */
final class TikaProcessResult {

  public function __construct(
    public readonly bool $successful,
    public readonly bool $timedOut,
    public readonly string $output,
    public readonly string $errorOutput,
  ) {}

}
