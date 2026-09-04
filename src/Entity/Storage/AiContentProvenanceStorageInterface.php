<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Storage;

use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface;

/**
 * Defines the storage handler for ai_content_provenance entities.
 *
 * Records are keyed on (entity_type, entity_id, revision_id); lookups always
 * filter the entity type, ids alone collide across entity types.
 */
interface AiContentProvenanceStorageInterface extends ContentEntityStorageInterface {

  /**
   * Loads the provenance record for one revision.
   *
   * @param string $entity_type_id
   *   The tracked entity type id.
   * @param int $entity_id
   *   The tracked entity id.
   * @param int $revision_id
   *   The tracked revision id.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface|null
   *   The record, or NULL when there is none.
   */
  public function loadForRevision(string $entity_type_id, int $entity_id, int $revision_id): ?AiContentProvenanceInterface;

  /**
   * Loads the provenance records for several revisions of one entity.
   *
   * @param string $entity_type_id
   *   The tracked entity type id.
   * @param int $entity_id
   *   The tracked entity id.
   * @param int[] $revision_ids
   *   The revision ids to look up.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface[]
   *   The records keyed by revision id; revisions without a record are absent.
   */
  public function loadForRevisions(string $entity_type_id, int $entity_id, array $revision_ids): array;

  /**
   * Loads every provenance record referencing a session.
   *
   * @param int $session_id
   *   The editorial session id.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface[]
   *   The records keyed by id.
   */
  public function loadForSession(int $session_id): array;

  /**
   * Loads every provenance record referencing a message.
   *
   * @param int $message_id
   *   The conversation message id.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface[]
   *   The records keyed by id.
   */
  public function loadForMessage(int $message_id): array;

  /**
   * Deletes every provenance record of a tracked entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The tracked entity being deleted.
   */
  public function deleteForEntity(EntityInterface $entity): void;

  /**
   * Deletes the provenance record of one tracked revision.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The revision being deleted.
   */
  public function deleteForRevision(EntityInterface $entity): void;

}
