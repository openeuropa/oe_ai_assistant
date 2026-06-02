<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\TypedData\TypedDataInternalPropertiesHelper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 *  - `x-truncated`: TRUE on a recursion-budget or cycle-guard hit.
 * These are non-standard JSON Schema and are intended for LLM consumption,
 * not for strict validators (which may warn or strip them).
 *
 * Denormalize-input shape: every field emits `{type: "array",
 * items: {type: "object", ...}}` so the LLM's output is consumable by
 * `$serializer->deserialize()` without translation. Plain references
 * carry `properties: {target_id: ...}`; entity_reference_revisions emits
 * a `oneOf` over per-bundle variants so paragraph payloads are delivered
 * inline as discriminated unions.
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
   * risks the LLM emitting hallucinated timestamps that would flow through
   * `$serializer->deserialize()` into the entity unchanged, bypassing
   * Drupal's revision tracking.
   *
   * @todo Replace with class-hierarchy detection
   *   (is_a($itemClass, CreatedItem::class) || is_a($itemClass, ChangedItem::class))
   *   so custom entities with non-standard timestamp field names are also caught.
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
   * Generous on purpose. Comprehensive schemas are preferred over compact
   * ones; size optimization can be addressed separately if needed. 6 covers
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
    #[Autowire(service: 'serializer')]
    private readonly SerializerInterface $serializer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityFieldManagerInterface $entityFieldManager,
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
   * Always wraps the item schema in `{type: "array", items: ...}`: the
   * denormalize-input shape requires every field to be array-shaped so the
   * LLM's output matches what `$serializer->deserialize()` expects without
   * translation.
   *
   * Per-item shaping lives in composeItem(); enrichment in enrichField();
   * reference handling in composeReferenceItem(). Future enrichments belong to
   * their own private helpers - do NOT inline new behavior here.
   *
   * Description is attached to the array wrapper (it describes the field, not
   * the leaf), AFTER composeItem() / enrichField() return their item schema.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $fieldItemList
   *   The field item list to introspect.
   * @param int $depth
   *   Remaining recursion budget, decremented before recursing through
   *   reference targets.
   *
   * @return array
   *   The field-level JSON Schema in `{type: "array", items: {...},
   *   maxItems?: N, description?: "..."}` shape.
   */
  private function composeField(FieldItemListInterface $fieldItemList, int $depth): array {
    $fieldDef = $fieldItemList->getFieldDefinition();

    // Reference fields short-circuit through composeReferenceItem.
    $itemClass = $fieldItemList->getItemDefinition()->getClass();
    if (is_a($itemClass, EntityReferenceItem::class, TRUE)) {
      $itemSchema = $this->composeReferenceItem($fieldDef, $depth - 1);
    }
    else {
      $itemSchema = $this->composeItem($fieldItemList);
      $itemSchema = $this->enrichField($itemSchema, $fieldDef);
    }

    // Always wrap as array. Cardinality 1 -> maxItems 1; cardinality N>1 ->
    // maxItems N; CARDINALITY_UNLIMITED -> no maxItems.
    $arraySchema = [
      'type' => 'array',
      'items' => $itemSchema,
    ];
    $cardinality = $fieldDef->getFieldStorageDefinition()->getCardinality();
    if ($cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
      $arraySchema['maxItems'] = $cardinality;
    }

    // Description on the field wrapper, not the item schema.
    $desc = $this->descriptionFor($fieldDef);
    if ($desc !== NULL) {
      $arraySchema['description'] = $desc;
    }

    return $arraySchema;
  }

  /**
   * Composes the per-item schema for a field's first item.
   *
   * Walks the item's non-computed, non-internal property definitions and
   * normalises each leaf via core's `'json_schema'` format. Always wraps as
   * `{type: "object", properties: {...}, required?: [...]}`. Single-property
   * collapse is intentionally absent because the denormalize-input shape
   * requires the LLM to emit the wrapped form (`[{"value": "..."}]`) so
   * deserialize accepts it without a translation layer.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $fieldItemList
   *   The field item list to introspect.
   *
   * @return array
   *   The per-item JSON Schema as `{type: "object", properties: {...},
   *   required?: [...]}`.
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
    // without an empty 'properties' key.
    if ($properties === []) {
      return ['type' => 'object'];
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
   * do NOT propagate field-instance constraints (allowed values, max length,
   * datetime granularity). This method injects those at the LEAF property
   * level (typically `properties.value`), not on the object envelope.
   * Because the composer always wraps items as objects, the constraint must
   * live on the constrained property to be meaningful.
   *
   * `description` is NOT injected here. composeField() attaches it to the
   * array wrapper one level up.
   *
   * @todo When adding constraints for property
   *   names other than `value` (e.g. text_long's `summary`, datetime's
   *   `end_value`), drop the isset() guards in favour of explicit
   *   property-name routing per field type. The current silent no-op is
   *   intentional today (every type we support has its constraint on
   *   `value`) but is a known foot-gun for the next round of enrichment.
   *   To be done in: OEL-4692.
   *
   * @param array $itemSchema
   *   The per-item schema produced by composeItem(); always `{type: "object",
   *   properties: {...}, ...}`.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDef
   *   The field definition supplying the enrichment metadata.
   *
   * @return array
   *   The per-item schema with field-instance metadata merged into the
   *   appropriate sub-property.
   */
  private function enrichField(array $itemSchema, FieldDefinitionInterface $fieldDef): array {
    $type = $fieldDef->getType();
    $settings = $fieldDef->getSettings();
    $storageSettings = $fieldDef->getFieldStorageDefinition()->getSettings();

    // List_string / list_integer / list_float -> enum on properties.value.
    if (in_array($type, ['list_string', 'list_integer', 'list_float'], TRUE)) {
      $allowed = $storageSettings['allowed_values'] ?? [];
      if ($allowed !== [] && isset($itemSchema['properties']['value'])) {
        $itemSchema['properties']['value']['enum'] = array_keys($allowed);
      }
    }

    // String storage max_length -> maxLength on properties.value.
    if ($type === 'string'
      && !empty($storageSettings['max_length'])
      && isset($itemSchema['properties']['value'])
    ) {
      $itemSchema['properties']['value']['maxLength'] = (int) $storageSettings['max_length'];
    }

    // Datetime: date vs date-time -> format on properties.value.
    if ($type === 'datetime' && isset($itemSchema['properties']['value'])) {
      $datetimeType = $settings['datetime_type'] ?? 'datetime';
      $itemSchema['properties']['value']['format'] = $datetimeType === 'date' ? 'date' : 'date-time';
    }

    return $itemSchema;
  }

  /**
   * Composes the per-item schema for a reference field.
   *
   * Emits a denormalize-input shape: `{type: "object", properties: {target_id:
   * <leaf>, alt?, title?, ...}, x-targetType: "<entity_type>"}`. For
   * entity_reference_revisions (paragraph-style inline entities), recurses
   * into each allowed target bundle and emits a `oneOf` over the bundle
   * variants: the LLM picks one bundle per item and emits its full payload
   * inline.
   *
   * Description is NOT injected here. composeField() attaches it to the
   * array wrapper one level up after this method returns. This mirrors
   * enrichField()'s contract.
   *
   * Known limitation for image/file fields: alt-text and title appear under
   * `properties` (the LLM CAN emit them) but file_extensions / max_filesize /
   * max_resolution constraints are not surfaced. Acceptable today because
   * images are typically attached post-draft.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDef
   *   The reference field definition.
   * @param int $depth
   *   Remaining recursion budget. Already decremented by composeField() before
   *   this method is called.
   *
   * @return array
   *   The per-item schema in denormalize-input shape.
   */
  private function composeReferenceItem(FieldDefinitionInterface $fieldDef, int $depth): array {
    $storage = $fieldDef->getFieldStorageDefinition();
    $targetType = $storage->getSetting('target_type');
    $handlerSettings = $fieldDef->getSetting('handler_settings') ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? NULL;

    if ($targetBundles === NULL) {
      $bundleInfo = $this->bundleInfo->getBundleInfo($targetType);
      $targetBundles = array_keys($bundleInfo);
    }
    else {
      $targetBundles = array_values($targetBundles);
    }

    // entity_reference_revisions: recurse into each target bundle, emit oneOf
    // so the LLM picks one bundle per inline item.
    if ($fieldDef->getType() === 'entity_reference_revisions') {
      // The bundle key is typically 'type' for paragraphs but we look it up
      // so this works for any inline-entity reference (e.g. revisionable
      // custom blocks). composeBundle() strips the bundle key via
      // SKIP_KEY_ROLES, so we re-inject it here as the discriminator the
      // denormalizer routes on.
      $targetEntityType = $this->entityTypeManager->getDefinition($targetType);
      $bundleKey = $targetEntityType->getKey('bundle');
      if (!$bundleKey) {
        throw new \InvalidArgumentException(sprintf(
          'Reference target "%s" has no bundle key; entity_reference_revisions only targets bundled entity types.',
          $targetType,
        ));
      }

      $variants = [];
      foreach ($targetBundles as $bundle) {
        $bundleSchema = $this->composeBundle($targetType, $bundle, $depth);
        // Inject the bundle-key property as the discriminator: an array of one
        // item whose target_id matches the bundle name. The LLM produces a
        // discriminated union the denormalizer can route.
        if (!isset($bundleSchema['properties'])) {
          $bundleSchema['properties'] = [];
        }
        $bundleSchema['properties'][$bundleKey] = [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => [
              'target_id' => ['type' => 'string', 'const' => $bundle],
            ],
          ],
          'maxItems' => 1,
        ];
        $variants[] = $bundleSchema;
      }
      // Include `type: object` alongside `oneOf` so the field-level invariant
      // "items.type === 'object'" holds (each variant IS an object schema; the
      // `oneOf` simply discriminates among allowed bundles). Standard JSON
      // Schema permits combining `type` with `oneOf`.
      return [
        'type' => 'object',
        'oneOf' => $variants,
        'x-targetType' => $targetType,
      ];
    }

    // entity_reference with inline_entity_form widget: treat like
    // entity_reference_revisions. The editor drafts full entities
    // inline, not just target_id references.
    //
    // @todo The inline detection currently relies on the form
    //   display widget type (inline_entity_form_*). This may not
    //   cover all cases where fields should be drafted inline
    //   (e.g. custom widgets, contrib modules with their own
    //   inline editing patterns). A more robust approach would be
    //   a dedicated per-field config flag that explicitly marks
    //   fields for inline drafting, independent of the widget.
    if ($fieldDef->getType() === 'entity_reference'
      && $this->isInlineEntityFormWidget($fieldDef)
    ) {
      $targetEntityType = $this->entityTypeManager->getDefinition($targetType);
      $bundleKey = $targetEntityType->getKey('bundle');

      $variants = [];
      foreach ($targetBundles as $bundle) {
        $bundleSchema = $this->composeBundle($targetType, $bundle, $depth);
        // Inject the bundle-key discriminator if the entity type
        // has bundles. Single-bundle entity types (e.g. user) skip
        // this.
        if ($bundleKey && isset($bundleSchema['properties'])) {
          $bundleSchema['properties'][$bundleKey] = [
            'type' => 'array',
            'items' => [
              'type' => 'object',
              'properties' => [
                'target_id' => ['type' => 'string', 'const' => $bundle],
              ],
            ],
            'maxItems' => 1,
          ];
        }
        $variants[] = $bundleSchema;
      }

      // Single target bundle: no need for oneOf.
      if (count($variants) === 1) {
        return array_merge($variants[0], [
          'x-targetType' => $targetType,
        ]);
      }

      return [
        'type' => 'object',
        'oneOf' => $variants,
        'x-targetType' => $targetType,
      ];
    }

    // Plain entity_reference / image / file / taxonomy: emit
    // {properties: {target_id, ...}, x-targetType}. target_id is integer for
    // numeric-id entities, string for string-keyed (config) entities; the
    // overwhelming common case is integer.
    $properties = [
      'target_id' => ['type' => 'integer'],
    ];

    // Image fields carry alt + title columns the LLM can populate.
    if ($fieldDef->getType() === 'image') {
      $properties['alt'] = ['type' => 'string'];
      $properties['title'] = ['type' => 'string'];
    }

    return [
      'type' => 'object',
      'properties' => $properties,
      'x-targetType' => $targetType,
    ];
  }

  /**
   * Checks if a field uses an inline_entity_form widget.
   *
   * Loads the default form display for the field's parent entity
   * type and bundle, then checks if the widget type starts with
   * "inline_entity_form".
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDef
   *   The field definition.
   *
   * @return bool
   *   TRUE if the field uses an inline entity form widget.
   */
  private function isInlineEntityFormWidget(FieldDefinitionInterface $fieldDef): bool {
    $entityTypeId = $fieldDef->getTargetEntityTypeId();
    $bundle = $fieldDef->getTargetBundle();
    if (!$entityTypeId || !$bundle) {
      return FALSE;
    }

    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface|null $formDisplay */
    $formDisplay = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load("$entityTypeId.$bundle.default");
    if (!$formDisplay) {
      return FALSE;
    }

    $component = $formDisplay->getComponent($fieldDef->getName());
    if (!$component || empty($component['type'])) {
      return FALSE;
    }

    return str_starts_with($component['type'], 'inline_entity_form');
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

  /**
   * Splits a composed schema into named field groups for sub-agents.
   *
   * Groups:
   * - main_fields: all scalar fields and plain entity references
   *   (taxonomy, media) that only need a target_id.
   * - One group per entity reference field targeting a draftable
   *   content entity type (entity_reference_revisions like
   *   paragraphs, or entity_reference to content entities like
   *   contacts).
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. "node").
   * @param string $bundle
   *   The bundle machine name (e.g. "oe_news").
   *
   * @return array
   *   Array of groups, each with 'groupId', 'label', 'fieldNames',
   *   and 'schemaSlice' keys.
   */
  public function splitSchemaIntoGroups(string $entityTypeId, string $bundle): array {
    $schema = $this->compose($entityTypeId, $bundle);
    $properties = $schema['properties'] ?? [];

    // Build a field label map from Drupal's field definitions.
    $fieldLabels = [];
    $definitions = $this->entityFieldManager
      ->getFieldDefinitions($entityTypeId, $bundle);
    foreach ($definitions as $name => $definition) {
      $label = $definition->getLabel();
      $fieldLabels[$name] = is_string($label) ? $label : (string) $label;
    }

    $mainFields = [];
    $entityGroups = [];

    foreach ($properties as $fieldName => $fieldSchema) {
      $items = $fieldSchema['items'] ?? [];
      $targetType = $items['x-targetType'] ?? NULL;

      if ($targetType !== NULL && !$this->isSimpleReferenceTarget($targetType)) {
        $label = $fieldLabels[$fieldName] ?? $fieldName;
        $entityGroups[] = [
          'groupId' => $fieldName,
          'label' => $label,
          'fieldNames' => [$fieldName],
          'schemaSlice' => [
            'type' => 'object',
            'properties' => [$fieldName => $fieldSchema],
          ],
        ];
      }
      else {
        $mainFields[$fieldName] = $fieldSchema;
      }
    }

    $groups = [];

    if (!empty($mainFields)) {
      $groups[] = [
        'groupId' => 'main_fields',
        'label' => 'Main fields',
        'fieldNames' => array_keys($mainFields),
        'schemaSlice' => [
          'type' => 'object',
          'properties' => $mainFields,
        ],
      ];
    }

    foreach ($entityGroups as $group) {
      $groups[] = $group;
    }

    return $groups;
  }

  /**
   * Checks if a target entity type is a simple reference (not draftable).
   *
   * Simple references like taxonomy_term and media only need a
   * target_id; they should not get their own sub-agent.
   *
   * @param string $targetType
   *   The entity type ID from x-targetType.
   *
   * @return bool
   *   TRUE if this is a simple reference target.
   */
  private function isSimpleReferenceTarget(string $targetType): bool {
    $simpleTypes = [
      'taxonomy_term',
      'media',
      'file',
      'user',
    ];
    return in_array($targetType, $simpleTypes, TRUE);
  }

}
