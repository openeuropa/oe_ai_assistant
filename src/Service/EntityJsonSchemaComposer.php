<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInternalPropertiesHelper;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Composes a JSON Schema document for a content-type bundle.
 *
 * Walks a stub entity's typed data, normalises leaf properties via Drupal
 * core's `'json_schema'` format (which ships real schemas for primitive
 * typed-data plugins), and assembles a per-bundle JSON Schema document.
 * Field-instance metadata that core does not propagate (allowed values,
 * max length, target bundles, etc.) is merged in subsequent tasks.
 */
class EntityJsonSchemaComposer {

  /**
   * Entity-type key roles whose mapped field name is skipped from the schema.
   *
   * 'label' and 'published' are intentionally absent - those map to title
   * and status, which are part of the editorial draft.
   *
   * @var string[]
   */
  private const SKIP_KEY_ROLES = [
    'id',
    'revision',
    'bundle',
    'langcode',
    'uuid',
    'default_langcode',
    'revision_translation_affected',
    'owner',
  ];

  public function __construct(
    private readonly SerializerInterface $serializer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Composes the JSON Schema for an entity type + bundle.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. "node").
   * @param string $bundle
   *   The bundle machine name (e.g. "oe_news").
   *
   * @return array
   *   {properties: {field_name: per-field-schema}, ...}
   */
  public function compose(string $entityTypeId, string $bundle): array {
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
    if (!$entityType instanceof ContentEntityTypeInterface) {
      throw new \InvalidArgumentException(sprintf(
        '%s only supports content entity types, got "%s".',
        static::class,
        $entityTypeId,
      ));
    }

    $bundleKey = $entityType->getKey('bundle');
    $values = $bundleKey ? [$bundleKey => $bundle] : [];
    $stub = $this->entityTypeManager
      ->getStorage($entityTypeId)
      ->create($values);

    $properties = TypedDataInternalPropertiesHelper::getNonInternalProperties(
      $stub->getTypedData()
    );

    $skip = $this->buildSystemFieldSkipSet($entityType);

    $schemaProperties = [];
    $required = [];
    foreach ($properties as $fieldName => $fieldItemList) {
      if (isset($skip[$fieldName])) {
        continue;
      }
      $schemaProperties[$fieldName] = $this->composeField($fieldItemList);
      if ($fieldItemList->getFieldDefinition()->isRequired()) {
        $required[] = $fieldName;
      }
    }

    $schema = [
      'type' => 'object',
      'properties' => $schemaProperties,
    ];
    if (!empty($required)) {
      $schema['required'] = $required;
    }
    return $schema;
  }

  /**
   * Builds the set of system-managed field names to omit from the schema.
   *
   * Uses the entity type's own key declarations so the filter works for any
   * entity type (node, paragraph, taxonomy_term, etc.) without hard-coded
   * field names. Editorially meaningful keys ('label', 'published') are
   * intentionally KEPT - title and status are part of the editorial draft.
   *
   * @param \Drupal\Core\Entity\ContentEntityTypeInterface $entityType
   *   The entity type definition to introspect.
   *
   * @return array<string, true>
   *   Field names to skip, keyed by name for O(1) isset() checks.
   */
  private function buildSystemFieldSkipSet(ContentEntityTypeInterface $entityType): array {
    $keys = $entityType->getKeys();
    $skip = [];
    foreach (self::SKIP_KEY_ROLES as $role) {
      if (!empty($keys[$role])) {
        $skip[$keys[$role]] = TRUE;
      }
    }

    // Revision metadata fields (revision_uid, revision_timestamp,
    // revision_log, revision_default) are all auto-managed by the
    // revision system.
    foreach ($entityType->getRevisionMetadataKeys() as $fieldName) {
      if ($fieldName !== '') {
        $skip[$fieldName] = TRUE;
      }
    }

    return $skip;
  }

  /**
   * Composes the schema for one field, applying cardinality wrapping.
   *
   * Multi-cardinality fields become {type: "array", items: ...}; single-
   * cardinality fields return the item schema directly. Per-item shaping
   * lives in composeItem(); enrichment (Task 3) and reference handling
   * (Task 4) belong to their own private helpers - do NOT inline new
   * behavior here.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $fieldItemList
   *   The field item list to introspect.
   *
   * @return array
   *   The field-level JSON Schema, wrapped as an array when cardinality > 1.
   */
  private function composeField(FieldItemListInterface $fieldItemList): array {
    $fieldDef = $fieldItemList->getFieldDefinition();
    $itemSchema = $this->composeItem($fieldItemList);

    $cardinality = $fieldDef->getFieldStorageDefinition()->getCardinality();
    if ($cardinality === 1) {
      return $itemSchema;
    }
    $arraySchema = [
      'type' => 'array',
      'items' => $itemSchema,
    ];
    if ($cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
      $arraySchema['maxItems'] = $cardinality;
    }
    return $arraySchema;
  }

  /**
   * Composes the per-item schema for a field's first item.
   *
   * Walks the item's non-computed, non-internal property definitions,
   * normalises each leaf via core's `'json_schema'` format, then either
   * collapses single-property items to the leaf schema directly (so
   * `title.value` -> `{type: "string"}` rather than nesting under
   * `properties.value`) or wraps multi-property items as a `{type: "object",
   * properties: {...}}` schema with a `required` list.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $fieldItemList
   *   The field item list to introspect.
   *
   * @return array
   *   The per-item JSON Schema (leaf primitive for single-property fields,
   *   object for multi-property fields).
   */
  private function composeItem(FieldItemListInterface $fieldItemList): array {
    // Materialise an item so we can read property definitions. Safe: this
    // is a stub entity scoped to schema composition, never persisted.
    if ($fieldItemList->count() === 0) {
      $fieldItemList->appendItem([]);
    }
    $item = $fieldItemList->first();
    $itemDef = $item->getDataDefinition();

    $properties = [];
    $required = [];
    foreach ($itemDef->getPropertyDefinitions() as $propName => $propDef) {
      if ($propDef->isComputed() || $propDef->isInternal()) {
        continue;
      }
      $properties[$propName] = $this->serializer->normalize(
        $item->get($propName),
        'json_schema'
      );
      if ($propDef->isRequired()) {
        $required[] = $propName;
      }
    }

    // All properties were computed/internal - emit a permissive object schema
    // without an empty 'properties' key. This is rare in practice but possible
    // for fields where every item-property is excluded by the walk.
    if ($properties === []) {
      return ['type' => 'object'];
    }

    // Single-property collapse: title.value -> string, not
    // {value: {type: string}}.
    if (count($properties) === 1) {
      $only = array_key_first($properties);
      return $properties[$only];
    }

    $schema = [
      'type' => 'object',
      'properties' => $properties,
    ];
    if (!empty($required)) {
      $schema['required'] = $required;
    }
    return $schema;
  }

}
