<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
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
   * Implements hook_entity_insert().
   *
   * Summarises supported working-material documents as soon as they are saved.
   */
  #[Hook('entity_insert')]
  public function summarizeInsertedDocument(EntityInterface $entity): void {
    if (!$entity instanceof MediaInterface || !$this->documentSummaryExtractor->supports($entity)) {
      return;
    }
    try {
      $this->documentSummaryExtractor->extractAndSave($entity);
    }
    catch (DocumentSummaryExtractionException $e) {
      // The document itself is still valid working material. Extraction
      // failures are logged by the extractor and leave the summary empty.
    }

  }

  /**
   * Implements hook_entity_update().
   *
   * Re-summarises supported working-material documents only when their source
   * file changes. Summary-only saves from the extractor therefore do not loop.
   */
  #[Hook('entity_update')]
  public function summarizeUpdatedDocument(EntityInterface $entity): void {
    if (!$entity instanceof MediaInterface || !$this->documentSummaryExtractor->supports($entity)) {
      return;
    }

    $details = $this->getStorageDetails($entity);
    if ($details === NULL || !$this->sourceFileChanged($entity, $details['sourceField'])) {
      return;
    }

    $this->clearSummary($entity, $details['summaryField']);
    try {
      $this->documentSummaryExtractor->extractAndSave($entity);
    }
    catch (DocumentSummaryExtractionException $e) {
      // Keep the file replacement/update and leave the summary empty.
    }
  }

  /**
   * Returns configured storage details for the media bundle.
   *
   * @return array{category: string, sourceField: string, summaryField: string}|null
   *   Storage details, or NULL for unsupported bundles.
   */
  private function getStorageDetails(MediaInterface $media): ?array {
    $bundles = ContextDocumentStorage::workingMaterialBundles();
    return $bundles[$media->bundle()] ?? NULL;
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
