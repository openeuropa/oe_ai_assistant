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
   * Checks whether this media entity is already being summarised.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return bool
   *   TRUE when extraction is active for the media entity.
   */
  public function isExtracting(MediaInterface $media): bool;

  /**
   * Sends the media source file to the configured provider.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The supported working-material media entity.
   *
   * @return string
   *   The extracted summary.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\DocumentSummaryExtractionException
   *   When the media cannot be summarised or the provider returns no summary.
   */
  public function extract(MediaInterface $media): string;

}
