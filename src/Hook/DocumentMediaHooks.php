<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;

/**
 * Hooks for working-material document media entities.
 */
final class DocumentMediaHooks {

  use AutowireTrait;

  /**
   * Documents processed per cron run.
   */
  private const int CRON_BATCH = 5;

  /**
   * Seconds after which an in-flight document counts as abandoned.
   */
  private const int STALE_AFTER = 600;

  public function __construct(
    private readonly DocumentExtractionProcessorInterface $processor,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Safety net for documents the app never triggered, lost requests and
   * crashed runs. Each picked document is driven to done or error.
   */
  #[Hook('cron')]
  public function processPendingDocuments(): void {
    $this->processor->processPending(self::CRON_BATCH, self::STALE_AFTER);
  }

  /**
   * Implements hook_media_presave().
   *
   * A new document, or one whose file was replaced, is handed back to the
   * processor for a fresh run; the processor owns the reset itself.
   */
  #[Hook('media_presave')]
  public function scheduleExtraction(MediaInterface $media): void {
    if (!$media->hasField(DocumentExtractionProcessorInterface::STATE_FIELD)) {
      return;
    }
    if (!$media->isNew() && !$this->sourceFileChanged($media)) {
      return;
    }

    $this->processor->schedule($media);
  }

  /**
   * Implements hook_ENTITY_TYPE_access() for media.
   */
  #[Hook('media_access')]
  public function mediaAccess(MediaInterface $media, $operation, AccountInterface $account): AccessResultInterface {
    if ($media->bundle() !== 'ai_context_document') {
      return AccessResult::neutral();
    }

    $referenced = $media->get('oe_ai_session')->referencedEntities();
    $session = reset($referenced);
    if (!$session instanceof AiEditorialSessionInterface) {
      // The session reference is required, but ensure dangling reference
      // access if forbidden just in case session is removed and media persist.
      return AccessResult::forbidden('Document has no associated session.')
        ->addCacheableDependency($media);
    }

    $session_operation = $operation === 'view' ? 'view' : 'update';

    return AccessResult::forbiddenIf(!$session->access($session_operation, $account))
      ->addCacheableDependency($session);
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
