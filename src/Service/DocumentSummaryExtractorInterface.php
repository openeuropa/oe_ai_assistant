<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\media\MediaInterface;

/**
 * Extracts and stores AI-generated summaries for working-material documents.
 */
interface DocumentSummaryExtractorInterface {

  /**
   * Checks whether the media entity can be summarised by this extractor.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return bool
   *   TRUE when the media bundle and required fields are supported.
   */
  public function supports(MediaInterface $media): bool;

  /**
   * Sends the media source file to the configured provider and saves a summary.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The supported working-material media entity.
   *
   * @return string
   *   The extracted summary saved to the media entity.
   *
   * @throws \RuntimeException
   *   When the media cannot be summarised or the provider returns no summary.
   */
  public function extractAndSave(MediaInterface $media): string;

}
