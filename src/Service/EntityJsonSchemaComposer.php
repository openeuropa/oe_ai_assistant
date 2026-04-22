<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\TypedData\TypedDataInternalPropertiesHelper;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Composes a JSON Schema document for a content-type bundle.
 *
 * Walks a stub entity's typed data, normalises leaf properties via Drupal
 * core's `'json_schema'` format (which ships real schemas for primitive
 * typed-data plugins), and assembles a per-bundle JSON Schema document.
 * Field-instance metadata that core does not propagate (allowed values,
 * max length, target bundles, etc.) is merged on top by enrichField() and
 * composeReferenceItem().
 *
 * Reference fields emit JSON Schema extension keys with the `x-` prefix:
 *  - `x-targetType`: the referenced entity type ID.
 *  - `x-targetBundles`: list of allowed target bundle machine names.
 *  - `x-bundles`: per-bundle composed schemas (entity_reference_revisions
 *    only).
 *  - `x-truncated`: TRUE on a recursion-budget or cycle-guard hit.
 * These are non-standard JSON Schema and are intended for LLM consumption,
 * not for strict validators (which may warn or strip them).
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

  /**
   * Field machine names that are auto-managed and should never be drafted.
   *
   * These are NOT entity-type keys (so they're not caught by SKIP_KEY_ROLES)
   * but Drupal manages their values on save. Including them in the schema
   * risks the LLM emitting hallucinated timestamps that DraftFieldMapper
   * would then write into the entity, bypassing Drupal's revision tracking.
   *
   * @todo Replace with class-hierarchy detection
   *   (is_a($itemClass, CreatedItem::class) || is_a($itemClass, ChangedItem::class))
   *   so custom entities with non-standard timestamp field names are also caught.
   *   Tracked under post-OEL-4691 hardening.
   *
   * @var string[]
   */
  private const AUTO_MANAGED_FIELD_NAMES = [
    'created',
    'changed',
  ];

  /**
   * Maximum depth for recursive entity-reference composition.
   *
   * Generous on purpose - this ticket prefers comprehensive schemas over
   * compact ones (size optimization is OEL-4692's job). 6 covers
   * landing-page-with-nested-paragraph-with-nested-paragraph and still
   * provides a hard stop against runaway cycles.
   */
  private const MAX_RECURSION_DEPTH = 6;

  /**
   * Visited entity-type:bundle pairs in the current composition tree.
   *
   * Reset at every public compose() entry; populated/popped during
   * composeBundle() recursion to break cycles. Use try/finally to ensure
   * the entry is removed even when composition throws.
   *
   * @var array<string, true>
   */
  private array $visited = [];

  public function __construct(
    private readonly SerializerInterface $serializer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
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
    // Reset cycle guard at every public entry - per-request isolation.
    $this->visited = [];
    return $this->composeBundle($entityTypeId, $bundle, self::MAX_RECURSION_DEPTH);
  }

  /**
   * Composes the schema for a specific entity type + bundle at a given depth.
   *
   * Recursive entry point used both for the top-level node and for paragraph
   * sub-bundles reached via composeReferenceItem(). Depth and visited-set
   * guards prevent runaway recursion on circular reference configurations.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   * @param int $depth
   *   Remaining recursion budget. Returns a truncated stub when 0 or when
   *   this entityType:bundle is already in $visited.
   *
   * @return array
   *   The composed schema for this bundle, or a truncation stub.
   */
  private function composeBundle(string $entityTypeId, string $bundle, int $depth): array {
    $visitKey = "$entityTypeId:$bundle";
    if (isset($this->visited[$visitKey]) || $depth <= 0) {
      return ['type' => 'object', 'x-truncated' => TRUE];
    }
    $this->visited[$visitKey] = TRUE;

    try {
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
        $schemaProperties[$fieldName] = $this->composeField($fieldItemList, $depth);
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
    finally {
      unset($this->visited[$visitKey]);
    }
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

    // Auto-managed base fields - typically timestamps not in entity-type keys.
    foreach (self::AUTO_MANAGED_FIELD_NAMES as $fieldName) {
      $skip[$fieldName] = TRUE;
    }

    return $skip;
  }

  /**
   * Composes the schema for one field, applying cardinality wrapping.
   *
   * Multi-cardinality fields become {type: "array", items: ...}; single-
   * cardinality fields return the item schema directly.
   *
   * Per-item shaping lives in composeItem(); enrichment in enrichField();
   * reference handling in composeReferenceItem(). Future enrichments
   * belong to their own private helpers - do NOT inline new behavior here.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $fieldItemList
   *   The field item list to introspect.
   * @param int $depth
   *   Remaining recursion budget, decremented before recursing through
   *   reference targets.
   *
   * @return array
   *   The field-level JSON Schema, wrapped as an array when cardinality > 1.
   */
  private function composeField(FieldItemListInterface $fieldItemList, int $depth): array {
    $fieldDef = $fieldItemList->getFieldDefinition();

    // Reference fields short-circuit through composeReferenceItem rather than
    // walking item properties (which would yield {target_id,
    // target_revision_id} primitive bags - useless for the LLM). Detect via
    // the FieldItem class hierarchy so any FieldItem extending
    // EntityReferenceItem (image, file, oe_media_entity_reference,
    // skos_concept_entity_reference, ...) routes through the reference path.
    $itemClass = $fieldItemList->getItemDefinition()->getClass();
    if (is_a($itemClass, EntityReferenceItem::class, TRUE)) {
      $itemSchema = $this->composeReferenceItem($fieldDef, $depth - 1);
    }
    else {
      $itemSchema = $this->composeItem($fieldItemList);
      $itemSchema = $this->enrichField($itemSchema, $fieldDef);
    }

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

  /**
   * Layers field-instance metadata onto a per-item schema.
   *
   * Core's leaf normalisers know primitive types and basic formats but
   * do NOT propagate field-instance constraints (allowed values, max
   * length, datetime granularity, descriptions). This method injects
   * those on top of the core leaf schema.
   *
   * Operates on the schema produced by composeItem(): for single-property
   * fields that's the leaf schema directly, for multi-property fields
   * that's the {type: "object", properties: ...} envelope. Either way,
   * enum / maxLength / format / description attach at the schema's top
   * level.
   *
   * Limitation: for multi-property fields (where composeItem() returns
   * {type: "object", properties: ...}), enum/maxLength/format are merged
   * at the OBJECT envelope level, not on a sub-property. Today no field
   * type exhibits multi-property + max_length together; revisit this rule
   * if such a type is introduced.
   *
   * @param array $itemSchema
   *   The per-item schema produced by composeItem().
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDef
   *   The field definition supplying the enrichment metadata.
   *
   * @return array
   *   The per-item schema with field-instance metadata merged in.
   */
  private function enrichField(array $itemSchema, FieldDefinitionInterface $fieldDef): array {
    $type = $fieldDef->getType();
    $settings = $fieldDef->getSettings();
    $storageSettings = $fieldDef->getFieldStorageDefinition()->getSettings();

    // List_string / list_integer / list_float -> enum.
    if (in_array($type, ['list_string', 'list_integer', 'list_float'], TRUE)) {
      $allowed = $storageSettings['allowed_values'] ?? [];
      if ($allowed !== []) {
        $itemSchema['enum'] = array_keys($allowed);
      }
    }

    // String storage max_length.
    if ($type === 'string' && !empty($storageSettings['max_length'])) {
      $itemSchema['maxLength'] = (int) $storageSettings['max_length'];
    }

    // Datetime: date vs date-time.
    if ($type === 'datetime') {
      $datetimeType = $settings['datetime_type'] ?? 'datetime';
      $itemSchema['format'] = $datetimeType === 'date' ? 'date' : 'date-time';
    }

    // Description: prefer field description, fall back to label.
    $desc = $this->descriptionFor($fieldDef);
    if ($desc !== NULL) {
      $itemSchema['description'] = $desc;
    }

    return $itemSchema;
  }

  /**
   * Composes the per-item schema for a reference field.
   *
   * Emits x-targetType / x-targetBundles always. For entity_reference_revisions
   * (paragraph-style inline entities), recurses into each allowed target
   * bundle and emits the per-bundle schema under x-bundles. Plain
   * entity_reference fields skip recursion - the LLM is expected to
   * reference an existing entity, not draft one.
   *
   * Description handling is delegated to descriptionFor() so the rule
   * stays consistent with enrichField()'s description-with-fallback.
   *
   * Known limitation for image/file fields: Drupal stores alt-text, title,
   * file extensions, max filesize, and upload constraints as field-instance
   * settings. This composer emits only x-targetType / x-targetBundles for
   * such fields. The LLM has no signal that alt-text exists or what file
   * types are allowed. Acceptable today because images are typically
   * attached post-draft; revisit if the drafting plugin starts populating
   * media fields directly. (Tracked as future enhancement.)
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDef
   *   The reference field definition.
   * @param int $depth
   *   Remaining recursion budget after the caller's decrement, used when
   *   recursing into target bundles for entity_reference_revisions.
   *
   * @return array
   *   The per-item reference schema.
   */
  private function composeReferenceItem(FieldDefinitionInterface $fieldDef, int $depth): array {
    $storage = $fieldDef->getFieldStorageDefinition();
    $targetType = $storage->getSetting('target_type');
    $handlerSettings = $fieldDef->getSetting('handler_settings') ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? NULL;

    if ($targetBundles === NULL) {
      // No restriction: enumerate all bundles to keep the schema bounded.
      $bundleInfo = $this->bundleInfo->getBundleInfo($targetType);
      $targetBundles = array_keys($bundleInfo);
    }
    else {
      $targetBundles = array_values($targetBundles);
    }

    $schema = [
      'type' => 'object',
      'x-targetType' => $targetType,
      'x-targetBundles' => $targetBundles,
    ];

    // Recurse only for entity_reference_revisions (paragraph-style inline
    // entities).
    if ($fieldDef->getType() === 'entity_reference_revisions') {
      $bundles = [];
      foreach ($targetBundles as $bundle) {
        $bundles[$bundle] = $this->composeBundle($targetType, $bundle, $depth);
      }
      $schema['x-bundles'] = $bundles;
    }

    $desc = $this->descriptionFor($fieldDef);
    if ($desc !== NULL) {
      $schema['description'] = $desc;
    }
    return $schema;
  }

  /**
   * Returns the description string for a field, falling back to its label.
   *
   * Returns NULL when both description and label resolve to empty - so
   * callers can omit the 'description' key entirely rather than emitting
   * "description": "".
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDef
   *   The field definition to read.
   *
   * @return string|null
   *   The chosen description, or NULL when nothing usable is available.
   */
  private function descriptionFor(FieldDefinitionInterface $fieldDef): ?string {
    $desc = (string) ($fieldDef->getDescription() ?? '');
    if ($desc === '') {
      $desc = (string) $fieldDef->getLabel();
    }
    return $desc === '' ? NULL : $desc;
  }

}
