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
 * The AI assistant produces a draft as a JSON object whose keys are Drupal
 * field machine names and whose values are the proposed field content. This
 * service translates that flat (or nested) structure into Drupal entity
 * values and persists a new node.
 *
 * Supported field categories:
 * - Simple value fields (string, number, boolean, date, link, email, etc.)
 *   are set directly via Entity::set().
 * - Formatted text fields (text_long, text_with_summary, text) require a
 *   'value' + 'format' pair and receive the field's default format.
 * - Inline entity reference revisions fields (paragraphs) are handled
 *   recursively: each item is created as a child entity, saved, and
 *   attached by target_id + target_revision_id.
 *
 * Unsupported categories (deliberately skipped):
 * - Plain entity reference fields (media, taxonomy, node refs, SKOS
 *   concepts, open vocabulary references): these require the editor to
 *   pick an existing entity, which the AI cannot do reliably.
 */
class DraftFieldMapper {

  /**
   * Field types that represent entity references and should be skipped.
   *
   * Plain entity_reference fields point to existing entities (taxonomy
   * terms, users, other nodes, media items, controlled vocabulary
   * concepts, etc.). Creating or selecting those is outside the scope of
   * AI drafting, so they are silently ignored during field mapping.
   *
   * Note: entity_reference_revisions (paragraphs) is NOT in this list
   * because inline paragraph entities are created from the draft data.
   *
   * @var string[]
   */
  protected const SKIPPED_REFERENCE_TYPES = [
    'entity_reference',
    'skos_concept_entity_reference',
    'open_vocabulary_reference',
  ];

  /**
   * Constructs a new DraftFieldMapper.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Used to load entity storage handlers (node_type, paragraph, etc.) and
   *   to create new entity instances for inline entities.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Used to load field definitions so field types can be inspected before
   *   attempting to set values.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInformation
   *   Used to detect whether a node type uses Content Moderation, and to
   *   set the appropriate initial moderation state.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly ModerationInformationInterface $moderationInformation,
  ) {}

  /**
   * Creates a node from draft field values.
   *
   * Validates the bundle exists, creates a new unsaved node, maps all
   * provided fields onto it, sets the initial moderation/publication state
   * to unpublished, then saves and returns the node.
   *
   * @param string $bundle
   *   The content type machine name (e.g. 'article', 'news').
   * @param array $fields
   *   The draft field values keyed by field machine name. Values may be
   *   scalars, associative arrays (for formatted text), or arrays of item
   *   arrays (for inline entities). Unknown field names are silently skipped.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node entity with a valid nid and revision ID.
   *
   * @throws \InvalidArgumentException
   *   If no node type with the given $bundle machine name exists.
   */
  public function createNode(string $bundle, array $fields): NodeInterface {
    // Verify the bundle exists before attempting to create a node.
    // loadMultiple() on node_type returns all node type config entities.
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
    // Always save drafts in an unpublished/draft state so the editor
    // can review and publish deliberately.
    $this->setModerationState($node);
    $node->save();

    return $node;
  }

  /**
   * Maps draft fields onto an entity.
   *
   * Iterates over the provided field values, looks up each field's type
   * from the entity's field definitions, and dispatches to the appropriate
   * mapping method. Fields not present in the entity's definitions are
   * silently skipped.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to set field values on (node, paragraph, or other
   *   fieldable entity type).
   * @param string $entityTypeId
   *   The entity type ID (e.g. 'node', 'paragraph').
   * @param string $bundle
   *   The bundle machine name.
   * @param array $fields
   *   The field values keyed by field machine name.
   */
  protected function mapFields($entity, string $entityTypeId, string $bundle, array $fields): void {
    $fieldDefinitions = $this->entityFieldManager
      ->getFieldDefinitions($entityTypeId, $bundle);

    foreach ($fields as $fieldName => $value) {
      // Skip fields that do not exist on this entity type/bundle.
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

      // entity_reference_revisions is used for paragraph fields. Each item
      // in the value array represents one paragraph with 'type' and 'fields'.
      if ($fieldType === 'entity_reference_revisions' && is_array($value)) {
        $this->mapInlineEntities($entity, $fieldName, $definition, $value);
        continue;
      }

      // Formatted text fields require an explicit text format. We wrap the
      // value with the field's configured default format so Drupal does not
      // reject the value or fall back to a dangerous format.
      if (in_array($fieldType, ['text_long', 'text_with_summary', 'text'], TRUE)) {
        $this->mapFormattedTextField($entity, $fieldName, $definition, $value);
        continue;
      }

      // All other field types (string, integer, boolean, datetime, link,
      // email, telephone, etc.) accept scalar values via Entity::set().
      $entity->set($fieldName, $value);
    }
  }

  /**
   * Maps a formatted text field, using the field's default format.
   *
   * Drupal formatted text fields store both the text value and the text
   * format identifier. The AI draft may provide the value as a plain
   * string or as an array with 'value' and optionally 'format' keys.
   * In either case we supply the field's first allowed format as the
   * default so Drupal does not reject the value.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity on which to set the field value.
   * @param string $fieldName
   *   The field machine name.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition, used to look up the default text format.
   * @param mixed $value
   *   The field value. May be a plain string, or an array with at least
   *   a 'value' key and an optional 'format' key.
   */
  protected function mapFormattedTextField($entity, string $fieldName, $definition, mixed $value): void {
    if (is_array($value) && isset($value['value'])) {
      // Use the caller-supplied format if provided, otherwise fall back to
      // the field's first allowed format.
      $format = $value['format'] ?? $this->getDefaultTextFormat($definition);
      $entity->set($fieldName, [
        'value' => $value['value'],
        'format' => $format,
      ]);
    }
    elseif (is_string($value)) {
      // Plain string value: wrap it with the default format.
      $entity->set($fieldName, [
        'value' => $value,
        'format' => $this->getDefaultTextFormat($definition),
      ]);
    }
    // Non-string, non-array values (e.g. NULL, integer) are silently ignored
    // to avoid type errors from the field system.
  }

  /**
   * Gets the default text format for a formatted text field.
   *
   * Reads the field's 'allowed_formats' setting and returns the first
   * element. When no allowed formats are configured the field accepts any
   * format, so 'plain_text' is used as a safe conservative default.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The formatted text field definition.
   *
   * @return string
   *   The text format machine name (e.g. 'plain_text', 'basic_html').
   */
  protected function getDefaultTextFormat($definition): string {
    $settings = $definition->getSettings();
    // allowed_formats is an indexed array of format machine names. The
    // field may restrict which formats editors can choose; the first
    // entry is used as the default for AI-generated content.
    return $settings['allowed_formats'][0] ?? 'plain_text';
  }

  /**
   * Maps inline entity (paragraph) fields.
   *
   * For each item in the provided values array this method:
   * 1. Creates a new child entity of the appropriate type.
   * 2. Recursively maps the item's 'fields' using mapFields().
   * 3. Saves the child entity to obtain a stable ID and revision ID.
   * 4. Attaches the child to the parent via target_id + target_revision_id.
   *
   * Items that do not contain both a 'type' key and a 'fields' key are
   * silently skipped to tolerate partial or malformed AI output.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The parent entity on which to set the reference field.
   * @param string $fieldName
   *   The field machine name on the parent entity.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition used to read the target entity type setting.
   * @param array $values
   *   Array of inline entity item arrays. Each item must have:
   *   - type (string): the bundle machine name of the child entity.
   *   - fields (array): field values to map onto the child entity.
   */
  protected function mapInlineEntities($entity, string $fieldName, $definition, array $values): void {
    // The target_type setting on entity_reference_revisions fields is
    // typically 'paragraph', but it is read from the definition to support
    // other revisionable entity types.
    $targetType = $definition->getSetting('target_type') ?? 'paragraph';
    $references = [];

    foreach ($values as $item) {
      // Validate the minimum required keys before attempting to create the
      // child entity. Missing 'type' or 'fields' means the AI produced an
      // unusable item; skip it rather than raise a fatal error.
      if (!is_array($item) || !isset($item['type'], $item['fields'])) {
        continue;
      }

      // Create and populate the child entity, then save it to get IDs.
      $childEntity = $this->entityTypeManager
        ->getStorage($targetType)
        ->create(['type' => $item['type']]);

      // Recursively map the child's fields using the same logic as the
      // parent -- this handles deeply nested paragraph structures.
      $this->mapFields($childEntity, $targetType, $item['type'], $item['fields']);
      $childEntity->save();

      // Entity reference revision fields require both the entity ID and the
      // specific revision ID so Drupal tracks the exact revision in use.
      $references[] = [
        'target_id' => $childEntity->id(),
        'target_revision_id' => $childEntity->getRevisionId(),
      ];
    }

    // Only update the field when at least one valid child was created.
    // Calling set() with an empty array would clear any existing items.
    if (!empty($references)) {
      $entity->set($fieldName, $references);
    }
  }

  /**
   * Sets the moderation state to the initial unpublished state.
   *
   * When the node type uses Content Moderation the moderation state must
   * be set via the 'moderation_state' field (not $node->setPublished()).
   * 'draft' is the standard initial state in the standard Editorial workflow.
   * For unmoderated node types, the node is simply marked unpublished so the
   * editor can review before publishing.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity whose publication state should be initialised.
   */
  protected function setModerationState(NodeInterface $node): void {
    if ($this->moderationInformation->isModeratedEntity($node)) {
      // Content Moderation is enabled: set the workflow state field.
      $node->set('moderation_state', 'draft');
    }
    else {
      // No workflow: use the basic published flag.
      $node->setPublished(FALSE);
    }
  }

  /**
   * Gets the preview URL for a node.
   *
   * Moderated nodes may not have a canonical published URL until they are
   * fully published. For those nodes the /latest route shows the most
   * recent revision, including the draft. For unmoderated nodes the
   * canonical /node/{nid} path is used.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The saved node entity.
   *
   * @return string
   *   The Drupal-relative URL path to preview the node (e.g. '/node/42' or
   *   '/node/42/latest').
   */
  public function getPreviewUrl(NodeInterface $node): string {
    if ($this->moderationInformation->isModeratedEntity($node)) {
      // /latest shows the current draft revision for editors.
      return '/node/' . $node->id() . '/latest';
    }
    return '/node/' . $node->id();
  }

}
