<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Storage;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface;

/**
 * Storage handler for ai_content_provenance entities.
 */
class AiContentProvenanceStorage extends SqlContentEntityStorage implements AiContentProvenanceStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function loadForRevision(string $entity_type_id, int $entity_id, int $revision_id): ?AiContentProvenanceInterface {
    $records = $this->loadForRevisions($entity_type_id, $entity_id, [$revision_id]);
    return $records[$revision_id] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadForRevisions(string $entity_type_id, int $entity_id, array $revision_ids): array {
    if ($revision_ids === []) {
      return [];
    }
    $ids = $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->condition('revision_id', array_map('intval', $revision_ids), 'IN')
      ->execute();
    if (!$ids) {
      return [];
    }
    $by_revision = [];
    foreach ($this->loadMultiple($ids) as $record) {
      $by_revision[$record->getTrackedRevisionId()] = $record;
    }
    return $by_revision;
  }

  /**
   * {@inheritdoc}
   */
  public function loadForSession(int $session_id): array {
    $ids = $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('session.target_id', $session_id)
      ->execute();
    return $ids ? $this->loadMultiple($ids) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function loadForMessage(int $message_id): array {
    $ids = $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('message.target_id', $message_id)
      ->execute();
    return $ids ? $this->loadMultiple($ids) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function deleteForEntity(EntityInterface $entity): void {
    $ids = $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (int) $entity->id())
      ->execute();
    if ($ids) {
      $this->delete($this->loadMultiple($ids));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteForRevision(EntityInterface $entity): void {
    if (!$entity instanceof RevisionableInterface || $entity->getRevisionId() === NULL) {
      return;
    }
    $record = $this->loadForRevision(
      $entity->getEntityTypeId(),
      (int) $entity->id(),
      (int) $entity->getRevisionId(),
    );
    if ($record !== NULL) {
      $record->delete();
    }
  }

}
