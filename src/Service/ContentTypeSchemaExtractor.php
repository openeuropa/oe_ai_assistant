<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\node\NodeInterface;

/**
 * Extracts a JSON-serializable schema from a content type's field definitions.
 *
 * Recursively resolves referenced entities (paragraphs, media, and custom
 * OpenEuropa entity types) so downstream consumers (LLMs, API clients) know
 * the full structure of a piece of content without needing Drupal access.
 *
 * The resulting schema describes each field's machine name, human label,
 * normalised type, cardinality, required flag, constraints, and -- for
 * reference fields -- the target entity type with its allowed bundles
 * and their own fields (up to $maxDepth levels deep).
 *
 * Only entity types on the recursable allowlist have their nested fields
 * expanded; other referenced entities (nodes, taxonomy terms, etc.) appear
 * with metadata only, keeping the schema compact enough for LLM prompts.
 */
class ContentTypeSchemaExtractor {

  /**
   * Maps Drupal field type IDs to normalised schema type strings.
   *
   * These normalised types are used in the output schema so consumers
   * do not need to know Drupal-specific type identifiers. Types not
   * present in this map are emitted as 'unknown'.
   *
   * @var array<string, string>
   */
  public const TYPE_MAP = [
    'string' => 'string',
    'string_long' => 'string',
    'text' => 'html',
    'text_long' => 'html',
    'text_with_summary' => 'html',
    'integer' => 'number',
    'decimal' => 'number',
    'float' => 'number',
    'boolean' => 'boolean',
    'list_string' => 'select',
    'list_integer' => 'select',
    'list_float' => 'select',
    'entity_reference' => 'reference',
    'entity_reference_revisions' => 'reference',
    'image' => 'image',
    'file' => 'file',
    'datetime' => 'date',
    'daterange' => 'date',
    'link' => 'link',
    'email' => 'email',
    'telephone' => 'telephone',
  ];

  /**
   * Base field names that are always included even though they are base fields.
   *
   * By default only configurable (bundle-level) fields are included. These
   * three base fields are editorially meaningful and must be present in the
   * schema so the LLM can draft them.
   *
   * @var string[]
   */
  private const BASE_FIELD_WHITELIST = ['title', 'body', 'status'];

  /**
   * Entity types whose fields are recursed into by default.
   *
   * Other referenced entity types (node, taxonomy_term, etc.) will still
   * appear in the schema with target type and bundle labels, but their
   * fields will not be expanded. This keeps the output compact enough
   * for LLM consumption.
   *
   * @var string[]
   */
  private const DEFAULT_RECURSABLE_ENTITY_TYPES = [
    'paragraph',
    'media',
    'oe_contact',
    'oe_author',
    'oe_person',
    'oe_venue',
    'oe_document_reference',
    'oe_organisation',
  ];

  /**
   * Entity types to recurse into for the current extraction run.
   *
   * Reset to DEFAULT_RECURSABLE_ENTITY_TYPES at the start of each extract()
   * call so that per-call overrides do not bleed across requests.
   *
   * @var string[]
   */
  private array $recursableEntityTypes = self::DEFAULT_RECURSABLE_ENTITY_TYPES;

  /**
   * Constructs a new ContentTypeSchemaExtractor.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Used to load field definitions for a given entity type and bundle.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   Used to resolve bundle labels and enumerate allowed bundles on
   *   reference fields that do not restrict the target bundle.
   */
  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * Extracts the schema for the content type of the given node.
   *
   * Convenience wrapper around extract() that derives entity type and bundle
   * from an already-loaded node object.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose content type schema should be extracted.
   * @param int $maxDepth
   *   Maximum recursion depth for entity reference fields. Each level of
   *   nesting costs additional Drupal API calls and output size, so keep
   *   this low for LLM prompts (default 3 is usually sufficient).
   * @param string[]|null $recursableEntityTypes
   *   Entity types whose nested fields should be expanded, or NULL to use
   *   DEFAULT_RECURSABLE_ENTITY_TYPES.
   *
   * @return array
   *   Schema array with 'contentType', 'label', and 'fields' keys.
   */
  public function extractFromNode(NodeInterface $node, int $maxDepth = 3, ?array $recursableEntityTypes = NULL): array {
    return $this->extract('node', $node->bundle(), $maxDepth, $recursableEntityTypes);
  }

  /**
   * Extracts the schema for a given entity type and bundle.
   *
   * This is the primary entry point. It initialises the recursable entity
   * type list, resolves the bundle label, then delegates field extraction
   * to extractFields().
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. 'node', 'paragraph').
   * @param string $bundle
   *   The bundle machine name (e.g. 'article', 'text_with_image').
   * @param int $maxDepth
   *   Maximum recursion depth for references. A depth of 1 means reference
   *   fields show metadata only (no nested field lists).
   * @param string[]|null $recursableEntityTypes
   *   Entity types to recurse into, or NULL for the default set.
   *   Only these entity types will have their fields expanded; other
   *   references still appear with target type and bundle labels.
   *
   * @return array
   *   Associative array with keys:
   *   - contentType (string): the bundle machine name.
   *   - label (string): the human-readable bundle label.
   *   - fields (array): list of field schema arrays (see buildFieldSchema()).
   */
  public function extract(string $entityTypeId, string $bundle, int $maxDepth = 3, ?array $recursableEntityTypes = NULL): array {
    // Apply the per-call recursable entity type override, falling back to the
    // class constant default when NULL is passed.
    $this->recursableEntityTypes = $recursableEntityTypes ?? self::DEFAULT_RECURSABLE_ENTITY_TYPES;

    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
    // Cast to string: bundle labels may be TranslatableMarkup objects.
    $label = $bundleInfo[$bundle]['label'] ?? $bundle;

    return [
      'contentType' => $bundle,
      'label' => (string) $label,
      // Start recursion with an empty visited set; the set prevents infinite
      // loops on circular entity reference configurations.
      'fields' => $this->extractFields($entityTypeId, $bundle, $maxDepth, []),
    ];
  }

  /**
   * Extracts field definitions for an entity type/bundle pair.
   *
   * Iterates over all field definitions, skips those that should not be
   * exposed (computed fields, non-whitelisted base fields), and delegates
   * to buildFieldSchema() for each included field.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. 'node', 'paragraph').
   * @param string $bundle
   *   The bundle machine name.
   * @param int $depth
   *   Remaining recursion depth. When this reaches 1 reference fields are
   *   not expanded further.
   * @param array $visited
   *   Already-visited "entityType:bundle" strings used to detect circular
   *   reference chains and stop recursion.
   *
   * @return array
   *   Ordered list of field schema arrays.
   */
  private function extractFields(string $entityTypeId, string $bundle, int $depth, array $visited): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    $fields = [];

    foreach ($definitions as $fieldName => $fieldDefinition) {
      if (!$this->shouldIncludeField($fieldName, $fieldDefinition)) {
        continue;
      }

      $fields[] = $this->buildFieldSchema($fieldName, $fieldDefinition, $depth, $visited);
    }

    return $fields;
  }

  /**
   * Determines whether a field should be included in the schema.
   *
   * The inclusion rules are:
   * - Computed fields are always excluded (they are derived values, not
   *   editable inputs, and change with every entity load).
   * - Base fields listed in BASE_FIELD_WHITELIST are always included
   *   (title, body, status are editorially meaningful).
   * - All remaining configurable (bundle-level) fields are included.
   *   These have a field storage config entity regardless of naming
   *   prefix (field_, oe_, ewcms_, etc.).
   * - Other base fields (uid, created, changed, uuid, etc.) are excluded
   *   because they are system-managed and not useful for AI drafting.
   *
   * @param string $fieldName
   *   The field machine name.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field definition object.
   *
   * @return bool
   *   TRUE if the field should appear in the output schema.
   */
  private function shouldIncludeField(string $fieldName, FieldDefinitionInterface $fieldDefinition): bool {
    if ($fieldDefinition->isComputed()) {
      return FALSE;
    }

    if (in_array($fieldName, self::BASE_FIELD_WHITELIST, TRUE)) {
      return TRUE;
    }

    // Include all configurable (bundle) fields -- these have a field storage
    // config entity regardless of naming prefix (field_, oe_, ewcms_, etc.).
    return !$fieldDefinition->getFieldStorageDefinition()->isBaseField();
  }

  /**
   * Builds the schema array for a single field.
   *
   * Assembles core metadata (name, label, type, cardinality, required flag,
   * description, constraints) and -- for reference fields -- delegates to
   * resolveReference() to add target type and bundle information.
   *
   * @param string $fieldName
   *   The field machine name.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field definition.
   * @param int $depth
   *   Remaining recursion depth passed through to resolveReference().
   * @param array $visited
   *   Already-visited entity type/bundle pairs passed through to
   *   resolveReference().
   *
   * @return array
   *   Field schema array with keys: name, label, type, required, cardinality,
   *   description, constraints, and optionally reference.
   */
  private function buildFieldSchema(
    string $fieldName,
    FieldDefinitionInterface $fieldDefinition,
    int $depth,
    array $visited,
  ): array {
    $storageDefinition = $fieldDefinition->getFieldStorageDefinition();
    $drupalType = $storageDefinition->getType();
    // Map the Drupal-internal type to a normalised schema type string.
    // Falls back to 'unknown' for types not in TYPE_MAP.
    $schemaType = self::TYPE_MAP[$drupalType] ?? 'unknown';

    $schema = [
      'name' => $fieldName,
      'label' => (string) $fieldDefinition->getLabel(),
      'type' => $schemaType,
      'required' => $fieldDefinition->isRequired(),
      // Cardinality is -1 for unlimited, or a positive integer for a fixed max.
      'cardinality' => $storageDefinition->getCardinality(),
      // Cast description to string: it may be a TranslatableMarkup or NULL.
      'description' => (string) ($fieldDefinition->getDescription() ?? ''),
      'constraints' => $this->extractConstraints($fieldDefinition, $schemaType),
    ];

    // Reference fields need additional target information so the LLM knows
    // which entity types and bundles can be linked or embedded.
    if ($schemaType === 'reference') {
      $schema['reference'] = $this->resolveReference($fieldDefinition, $depth, $visited);
    }

    return $schema;
  }

  /**
   * Extracts type-specific constraints from field and storage settings.
   *
   * The constraints object is always returned; when no constraints apply
   * it is an empty stdClass so it serialises to {} in JSON rather than [].
   * This keeps the schema structure consistent regardless of field type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field definition (provides per-instance settings like min/max).
   * @param string $schemaType
   *   The normalised schema type from TYPE_MAP (e.g. 'string', 'number').
   *
   * @return object|array
   *   stdClass (empty object) when there are no constraints, or an
   *   associative array of constraint key => value pairs. PHP's
   *   json_encode() renders a non-empty array as [] and stdClass as {},
   *   so the empty stdClass ensures consistent JSON output.
   */
  private function extractConstraints(FieldDefinitionInterface $fieldDefinition, string $schemaType): object|array {
    $storageDefinition = $fieldDefinition->getFieldStorageDefinition();
    $fieldSettings = $fieldDefinition->getSettings();
    $storageSettings = $storageDefinition->getSettings();
    $constraints = [];

    switch ($schemaType) {
      case 'string':
        // Storage-level max_length restricts the column width in the database.
        $maxLength = $storageSettings['max_length'] ?? NULL;
        if ($maxLength) {
          $constraints['maxLength'] = (int) $maxLength;
        }
        break;

      case 'number':
        // Field-instance min/max are optional; empty string means unset.
        if (isset($fieldSettings['min']) && $fieldSettings['min'] !== '') {
          $constraints['min'] = $fieldSettings['min'];
        }
        if (isset($fieldSettings['max']) && $fieldSettings['max'] !== '') {
          $constraints['max'] = $fieldSettings['max'];
        }
        break;

      case 'select':
        // allowed_values is an associative array of value => label pairs.
        $allowedValues = $storageSettings['allowed_values'] ?? [];
        if ($allowedValues) {
          $constraints['allowedValues'] = $allowedValues;
        }
        break;

      case 'image':
      case 'file':
        // File upload constraints are stored as field-instance settings.
        if (!empty($fieldSettings['file_extensions'])) {
          $constraints['fileExtensions'] = $fieldSettings['file_extensions'];
        }
        if (!empty($fieldSettings['max_filesize'])) {
          $constraints['maxFilesize'] = $fieldSettings['max_filesize'];
        }
        // Image-specific resolution constraints.
        if (!empty($fieldSettings['max_resolution'])) {
          $constraints['maxResolution'] = $fieldSettings['max_resolution'];
        }
        if (!empty($fieldSettings['min_resolution'])) {
          $constraints['minResolution'] = $fieldSettings['min_resolution'];
        }
        break;

      case 'link':
        // link_type is a bitmask: 1 = internal, 2 = external, 3 = both.
        if (isset($fieldSettings['link_type'])) {
          $constraints['linkType'] = $fieldSettings['link_type'];
        }
        break;
    }

    // Return an empty object so JSON encodes as {} instead of [].
    // json_encode([]) produces "[]" (a JSON array) which would mislead
    // consumers expecting an object with named constraint keys.
    return $constraints ?: new \stdClass();
  }

  /**
   * Resolves a reference field into target type and bundle information.
   *
   * For each allowed target bundle this method produces a label and,
   * when the target entity type is on the recursable allowlist and the
   * depth/visited guards allow it, a nested 'fields' array describing
   * the bundle's own fields.
   *
   * Guards applied before recursing:
   * - The target entity type must be in $this->recursableEntityTypes.
   * - The remaining depth must be greater than 1 (depth 1 is the leaf).
   * - The "entityType:bundle" key must not already be in $visited
   *   (prevents infinite loops on circular reference configurations).
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The reference field definition.
   * @param int $depth
   *   Remaining recursion depth.
   * @param array $visited
   *   Already-visited "entityType:bundle" strings.
   *
   * @return array
   *   Array with keys:
   *   - targetType (string): target entity type ID.
   *   - targetBundles (array): map of bundle machine name to bundle data.
   *     Each bundle has a 'label' key and optionally a 'fields' array.
   */
  private function resolveReference(
    FieldDefinitionInterface $fieldDefinition,
    int $depth,
    array $visited,
  ): array {
    $storageDefinition = $fieldDefinition->getFieldStorageDefinition();
    $targetType = $storageDefinition->getSetting('target_type');
    $handlerSettings = $fieldDefinition->getSetting('handler_settings') ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? NULL;

    // When target_bundles is NULL the handler allows all bundles for this
    // entity type. Enumerate them from the bundle info service.
    if ($targetBundles === NULL) {
      $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
      $targetBundles = array_keys($bundleInfo);
    }
    else {
      // handler_settings stores bundles as an associative array keyed by
      // machine name; flatten to a simple list of machine names.
      $targetBundles = array_values($targetBundles);
    }

    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
    $resolvedBundles = [];

    foreach ($targetBundles as $targetBundle) {
      // Cast label to string: may be TranslatableMarkup.
      $bundleLabel = (string) ($bundleInfo[$targetBundle]['label'] ?? $targetBundle);
      $bundleData = ['label' => $bundleLabel];

      // Build the visit key used for the circular reference guard.
      $visitKey = "$targetType:$targetBundle";
      $isRecursable = in_array($targetType, $this->recursableEntityTypes, TRUE);
      $isAtDepthLimit = $depth <= 1;
      $isCircular = in_array($visitKey, $visited, TRUE);

      // Only recurse into entity types on the allowlist, when depth allows
      // it, and when this exact bundle has not already been visited in the
      // current branch of the recursion tree.
      if ($isRecursable && !$isAtDepthLimit && !$isCircular) {
        $bundleData['fields'] = $this->extractFields(
          $targetType,
          $targetBundle,
          $depth - 1,
          // Spread operator creates a new array with the current visit key
          // appended, leaving $visited unmodified for sibling iterations.
          [...$visited, $visitKey],
        );
      }

      $resolvedBundles[$targetBundle] = $bundleData;
    }

    return [
      'targetType' => $targetType,
      'targetBundles' => $resolvedBundles,
    ];
  }

}
