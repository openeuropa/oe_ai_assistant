<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Document\ContextDocumentStorage;
use Drupal\oe_ai_assistant\Exception\DocumentSummaryExtractionException;
use Drupal\oe_ai_assistant\Service\DocumentSummaryExtractorInterface;

/**
 * Contains hooks for working-material document media entities.
 */
final class DocumentMediaHooks {

  use AutowireTrait;

  public function __construct(
    private readonly DocumentSummaryExtractorInterface $documentSummaryExtractor,
  ) {}

  /**
   * Implements hook_media_presave().
   *
   * Summarises working-material documents and file replacements before save.
   */
  #[Hook('media_presave')]
  public function summarizeDocument(MediaInterface $media): void {
    if ($this->documentSummaryExtractor->isExtracting($media)
      || !$this->documentSummaryExtractor->supports($media)
    ) {
      return;
    }

    $details = ContextDocumentStorage::workingMaterialBundle($media->bundle());
    if ($details === NULL ||
      (!$media->isNew() && !$this->sourceFileChanged($media, $details['sourceField']))
    ) {
      return;
    }

    $this->clearSummary($media, $details['summaryField']);
    try {
      $media->set($details['summaryField'], [
        'value' => $this->documentSummaryExtractor->extract($media),
      ]);
    }
    catch (DocumentSummaryExtractionException $e) {
      // The document itself is still valid working material. Extraction
      // failures are logged by the extractor and leave the summary empty.
    }
  }

  /**
   * Checks whether the configured media source file target changed.
   */
  private function sourceFileChanged(MediaInterface $media, string $sourceField): bool {
    $original = $media->getOriginal();
    if (!$original instanceof MediaInterface || !$original->hasField($sourceField)) {
      return TRUE;
    }

    return (string) $original->get($sourceField)->target_id !== (string) $media->get($sourceField)->target_id;
  }

  /**
   * Clears a stale summary before a replacement file is extracted.
   */
  private function clearSummary(MediaInterface $media, string $summaryField): void {
    if (!$media->hasField($summaryField) || $media->get($summaryField)->isEmpty()) {
      return;
    }

    $media->set($summaryField, NULL);
  }

}
