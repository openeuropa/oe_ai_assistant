<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Maps draft JSON fields to Drupal field values and creates nodes.
 *
 * Handles simple fields (text, number, date, boolean, select) and inline
 * entities (paragraphs) recursively. Entity reference fields are skipped.
 */
class DraftFieldMapper {

  /**
   * Field types that represent entity references and should be skipped.
   */
  protected const SKIPPED_REFERENCE_TYPES = [
    'entity_reference',
    'skos_concept_entity_reference',
    'open_vocabulary_reference',
  ];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly ModerationInformationInterface $moderationInformation,
  ) {}

  /**
   * Creates a node from draft field values.
   *
   * @param string $bundle
   *   The content type machine name.
   * @param array $fields
   *   The draft field values keyed by field machine name.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node entity.
   *
   * @throws \InvalidArgumentException
   *   If the bundle does not exist.
   */
  public function createNode(string $bundle, array $fields): NodeInterface {
    $bundles = $this->entityTypeManager
      ->getStorage('node_type')
      ->loadMultiple();
    if (!isset($bundles[$bundle])) {
      throw new \InvalidArgumentException(
        sprintf('Content type "%s" does not exist.', $bundle)
      );
    }

    $node = Node::create(['type' => $bundle]);
    $this->mapFields($node, 'node', $bundle, $fields);
    $this->setModerationState($node);
    $node->save();

    return $node;
  }

  /**
   * Maps draft fields onto an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to set fields on.
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param array $fields
   *   The field values keyed by field machine name.
   */
  protected function mapFields($entity, string $entityTypeId, string $bundle, array $fields): void {
    $fieldDefinitions = $this->entityFieldManager
      ->getFieldDefinitions($entityTypeId, $bundle);

    foreach ($fields as $fieldName => $value) {
      if (!isset($fieldDefinitions[$fieldName])) {
        continue;
      }

      $definition = $fieldDefinitions[$fieldName];
      $fieldType = $definition->getType();

      // Skip entity reference fields (media, taxonomy, node refs, etc.)
      // but allow entity_reference_revisions (paragraphs).
      if (in_array($fieldType, self::SKIPPED_REFERENCE_TYPES, TRUE)) {
        continue;
      }

      // Handle inline entities (paragraphs).
      if ($fieldType === 'entity_reference_revisions' && is_array($value)) {
        $this->mapInlineEntities($entity, $fieldName, $definition, $value);
        continue;
      }

      // Handle formatted text fields.
      if (in_array($fieldType, ['text_long', 'text_with_summary', 'text'], TRUE)) {
        $this->mapFormattedTextField($entity, $fieldName, $definition, $value);
        continue;
      }

      // Simple value fields.
      $entity->set($fieldName, $value);
    }
  }

  /**
   * Maps a formatted text field, using the field's default format.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $fieldName
   *   The field name.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param mixed $value
   *   The field value.
   */
  protected function mapFormattedTextField($entity, string $fieldName, $definition, mixed $value): void {
    if (is_array($value) && isset($value['value'])) {
      $format = $value['format'] ?? $this->getDefaultTextFormat($definition);
      $entity->set($fieldName, [
        'value' => $value['value'],
        'format' => $format,
      ]);
    }
    elseif (is_string($value)) {
      $entity->set($fieldName, [
        'value' => $value,
        'format' => $this->getDefaultTextFormat($definition),
      ]);
    }
  }

  /**
   * Gets the default text format for a formatted text field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return string
   *   The text format machine name.
   */
  protected function getDefaultTextFormat($definition): string {
    $settings = $definition->getSettings();
    return $settings['allowed_formats'][0] ?? 'plain_text';
  }

  /**
   * Maps inline entity (paragraph) fields.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The parent entity.
   * @param string $fieldName
   *   The field name.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param array $values
   *   The inline entity values.
   */
  protected function mapInlineEntities($entity, string $fieldName, $definition, array $values): void {
    $targetType = $definition->getSetting('target_type') ?? 'paragraph';
    $references = [];

    foreach ($values as $item) {
      if (!is_array($item) || !isset($item['type'], $item['fields'])) {
        continue;
      }

      $childEntity = $this->entityTypeManager
        ->getStorage($targetType)
        ->create(['type' => $item['type']]);

      $this->mapFields($childEntity, $targetType, $item['type'], $item['fields']);
      $childEntity->save();

      $references[] = [
        'target_id' => $childEntity->id(),
        'target_revision_id' => $childEntity->getRevisionId(),
      ];
    }

    if (!empty($references)) {
      $entity->set($fieldName, $references);
    }
  }

  /**
   * Sets the moderation state to the initial unpublished state.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   */
  protected function setModerationState(NodeInterface $node): void {
    if ($this->moderationInformation->isModeratedEntity($node)) {
      $node->set('moderation_state', 'draft');
    }
    else {
      $node->setPublished(FALSE);
    }
  }

  /**
   * Gets the preview URL for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return string
   *   The preview URL path.
   */
  public function getPreviewUrl(NodeInterface $node): string {
    if ($this->moderationInformation->isModeratedEntity($node)) {
      return '/node/' . $node->id() . '/latest';
    }
    return '/node/' . $node->id();
  }

}
