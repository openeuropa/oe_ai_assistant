<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Storage;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Storage handler for ai_conversation_message entities.
 *
 * Follows the Drupal core pattern of putting entity-specific queries on the
 * storage handler (see TermStorage::loadTree and CommentStorage::loadThread).
 */
class AiConversationMessageStorage extends SqlContentEntityStorage implements AiConversationMessageStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function loadTranscript(EntityInterface $host): array {
    $ids = $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('host_entity_type', $host->getEntityTypeId())
      ->condition('host_entity_id', (int) $host->id())
      ->condition('parent', NULL, 'IS NULL')
      ->condition('role', 'error', '<>')
      ->sort('created')
      ->sort('id')
      ->execute();
    return $ids ? $this->loadMultiple($ids) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function loadTree(EntityInterface $host): array {
    $ids = $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('host_entity_type', $host->getEntityTypeId())
      ->condition('host_entity_id', (int) $host->id())
      ->sort('created')
      ->sort('id')
      ->execute();
    if (!$ids) {
      return [];
    }
    // Group every row by its parent id in one pass; a NULL parent (a root
    // turn) is keyed under 0, which is safe since entity ids start at 1.
    $childrenByParent = [];
    foreach ($this->loadMultiple($ids) as $message) {
      $childrenByParent[(int) $message->getParentId()][] = $message;
    }
    return $this->buildBranch($childrenByParent, 0);
  }

  /**
   * Builds one branch of the conversation tree.
   *
   * @param array $childrenByParent
   *   Messages grouped by parent id, root turns keyed under 0.
   * @param int $parentId
   *   The parent id whose children form this branch.
   *
   * @return array
   *   The child nodes, each with a message and its own children.
   */
  protected function buildBranch(array $childrenByParent, int $parentId): array {
    $branch = [];
    foreach ($childrenByParent[$parentId] ?? [] as $message) {
      $branch[] = [
        'message' => $message,
        'children' => $this->buildBranch($childrenByParent, (int) $message->id()),
      ];
    }
    return $branch;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteForHost(EntityInterface $host): void {
    $ids = $this->getQuery()
      ->accessCheck(FALSE)
      ->condition('host_entity_type', $host->getEntityTypeId())
      ->condition('host_entity_id', (int) $host->id())
      ->execute();
    if ($ids) {
      $this->delete($this->loadMultiple($ids));
    }
  }

}
