<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\file\FileInterface;

/**
 * Extracts the plain text of a document file.
 *
 * Consumers never see the engine: the extractor maps the file to a
 * document_loader type and lets the framework pick the loader.
 */
interface DocumentTextExtractorInterface {

  /**
   * Extracts the text of a managed file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The document file.
   *
   * @return string
   *   The extracted text, never empty.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\DocumentExtractionException
   *   When the extension is unsupported, the loader fails, or no text
   *   comes back.
   */
  public function extract(FileInterface $file): string;

}
