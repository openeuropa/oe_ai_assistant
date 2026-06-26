<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines the storage schema for the AI conversation message entity.
 *
 * Adds the composite index that serves the per-turn context rebuild and the
 * full-tree load. A multi-column index spanning separate base fields cannot be
 * declared on a BaseFieldDefinition, so it is added here.
 */
class AiConversationMessageStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);

    $base_table = $this->storage->getBaseTable();
    // Composite index over the conversation grouping and ordering columns.
    // All four are single-column base fields, so each DB column equals its
    // field name. Serves owner queries (with or without parent IS NULL),
    // ordered by created.
    $schema[$base_table]['indexes'] += [
      'ai_conversation_message__owner' => [
        'owner_entity_type',
        'owner_entity_id',
        'parent',
        'created',
      ],
    ];

    return $schema;
  }

}
