<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

/**
 * Extracts documents through the configured Apache Tika source.
 */
interface TikaClientInterface {

  /**
   * Extracts the text of a local file.
   *
   * @param string $path
   *   Absolute path of the file on disk.
   * @param string $accept
   *   The requested output: text/plain for text, text/html for XHTML.
   *
   * @return string
   *   The extracted content, trimmed, never empty.
   *
   * @throws \Drupal\document_loader_tika\Exception\TikaException
   *   When the file cannot be read, the configured source is unavailable,
   *   reports an error, or returns an empty body.
   */
  public function extract(string $path, string $accept = 'text/plain'): string;

  /**
   * Returns the active source version string, or NULL when unavailable.
   *
   * A quick probe with a short fixed timeout, independent of the configured
   * extraction timeout.
   */
  public function version(): ?string;

  /**
   * Returns whether the active source answers its version probe.
   */
  public function isAvailable(): bool;

}
