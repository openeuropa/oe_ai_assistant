<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
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
    private readonly Connection $database,
    private readonly SessionAccessQueryAlterInterface $sessionAccessQueryAlter,
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

    // Pass TRUE to keep the session's own cacheability instead of just the
    // media entity's.
    $session_access = $session->access($session_operation, $account, TRUE);
    return AccessResult::forbiddenIf(!$session_access->isAllowed())
      ->inheritCacheability($session_access);
  }

  /**
   * Implements hook_query_TAG_alter() for the 'media_access' tag.
   *
   * Covers listings; mediaAccess() above only covers single-entity access.
   */
  #[Hook('query_media_access_alter')]
  public function queryMediaAccessAlter(AlterableInterface $query): void {
    if (!$query instanceof SelectInterface) {
      return;
    }

    $accessible_sessions = $this->database->select('ai_editorial_session', 'base_table')
      ->fields('base_table', ['id']);
    $this->sessionAccessQueryAlter->queryAiEditorialSessionAccessAlter($accessible_sessions);

    $query->leftJoin('media__oe_ai_session', 'document_session', '%alias.entity_id = base_table.mid AND %alias.revision_id = base_table.vid');

    $query->condition($query->orConditionGroup()
      ->condition('base_table.bundle', 'ai_context_document', '!=')
      ->condition('document_session.oe_ai_session_target_id', $accessible_sessions, 'IN'));
  }

  /**
   * Implements hook_ENTITY_TYPE_create_access() for media.
   *
   * Closes the generic /media/add route, which has no session to validate
   * against.
   */
  #[Hook('media_create_access')]
  public function mediaCreateAccess(AccountInterface $account, array $context, $entity_bundle): AccessResultInterface {
    if ($entity_bundle !== 'ai_context_document') {
      return AccessResult::neutral();
    }

    if ($account->hasPermission('administer ai editorial sessions')) {
      return AccessResult::neutral()->cachePerPermissions();
    }

    return AccessResult::forbidden('Context documents can only be created through the AI editorial assistant plugin.')
      ->cachePerPermissions();
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
