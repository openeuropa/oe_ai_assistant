<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines the storage schema for the AI content provenance entity.
 */
final class AiContentProvenanceStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);

    $base_table = $this->storage->getBaseTable();
    $schema[$base_table]['unique keys']['ai_content_provenance__revision'] = [
      'entity_type',
      'entity_id',
      'revision_id',
    ];
    $schema[$base_table]['indexes']['ai_content_provenance__entity'] = [
      'entity_type',
      'entity_id',
    ];

    return $schema;
  }

}
