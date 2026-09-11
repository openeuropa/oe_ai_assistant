<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika;

/**
 * Talks to an Apache Tika server over its REST API.
 */
interface TikaClientInterface {

  /**
   * Extracts the text of a local file.
   *
   * @param string $path
   *   Absolute path of the file on disk.
   * @param string $accept
   *   The Accept header: text/plain for text, text/html for XHTML.
   *
   * @return string
   *   The extracted content, trimmed, never empty.
   *
   * @throws \Drupal\document_loader_tika\Exception\TikaException
   *   When the file cannot be read, the server cannot be reached, answers
   *   with a non-200 status, or returns an empty body.
   */
  public function extract(string $path, string $accept = 'text/plain'): string;

  /**
   * Returns the server version string, or NULL when it cannot be reached.
   */
  public function version(): ?string;

  /**
   * Returns whether the server answers its version endpoint.
   */
  public function isAvailable(): bool;

}
