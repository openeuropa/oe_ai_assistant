<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionWorkflowInterface;

/**
 * Hooks for working-material document media entities.
 */
final class DocumentMediaHooks {

  use AutowireTrait;

  /**
   * The field holding the full extracted text.
   */
  public const string EXTRACT_FIELD = 'oe_ai_document_extract';

  /**
   * The field holding the brief summary.
   */
  public const string SUMMARY_FIELD = 'oe_ai_document_summary';

  /**
   * Documents processed per cron run.
   */
  private const int CRON_BATCH = 5;

  /**
   * Seconds after which an in-flight document counts as abandoned.
   */
  private const int STALE_AFTER = 600;

  public function __construct(
    private readonly DocumentExtractionWorkflowInterface $workflow,
    private readonly DocumentExtractionProcessorInterface $processor,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Safety net for documents the app never triggered, lost requests and
   * crashed runs. Each picked document is driven to done or error.
   */
  #[Hook('cron')]
  public function processPendingDocuments(): void {
    $storage = $this->entityTypeManager->getStorage('media');
    foreach ($this->workflow->findPending(self::CRON_BATCH, self::STALE_AFTER) as $id => $reclaim) {
      $media = $storage->load($id);
      if ($media instanceof MediaInterface) {
        $this->processor->process($media, $reclaim);
      }
    }
  }

  /**
   * Implements hook_media_presave().
   *
   * A new document, or one whose file was replaced, goes back to scheduled
   * with its extract and summary cleared, so the pipeline runs again.
   */
  #[Hook('media_presave')]
  public function scheduleExtraction(MediaInterface $media): void {
    if (!$this->workflow->appliesTo($media)) {
      return;
    }
    if (!$media->isNew() && !$this->sourceFileChanged($media)) {
      return;
    }

    $media->set(self::EXTRACT_FIELD, NULL);
    $media->set(self::SUMMARY_FIELD, NULL);
    $media->set(DocumentExtractionWorkflowInterface::STATE_FIELD, DocumentExtractionWorkflowInterface::STATE_SCHEDULED);
  }

  /**
   * Checks whether the media source file target changed since the last save.
   */
  private function sourceFileChanged(MediaInterface $media): bool {
    $sourceField = $media->getSource()->getConfiguration()['source_field'] ?? '';
    $original = $media->getOriginal();
    if ($sourceField === '' || !$original instanceof MediaInterface || !$original->hasField($sourceField)) {
      return TRUE;
    }

    return (string) $original->get($sourceField)->target_id !== (string) $media->get($sourceField)->target_id;
  }

}
