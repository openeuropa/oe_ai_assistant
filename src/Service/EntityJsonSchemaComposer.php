<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
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
    foreach ($properties as $fieldName => $fieldItemList) {
      if (isset($skip[$fieldName])) {
        continue;
      }
      $schemaProperties[$fieldName] = $this->composeField($fieldItemList);
    }

    return [
      'properties' => $schemaProperties,
    ];
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
   * Composes the schema for a single field (FieldItemList).
   *
   * Task 1 minimal version: emit the property-level leaf schema for each
   * non-computed, non-internal property of the first item. Tasks 2-4 expand
   * this into a properly-wrapped object/array schema with enrichment.
   *
   * Tasks 2-4 add wrapping (composeItem), enrichment (enrichField), and
   * recursion (composeReferenceItem) as SEPARATE private helpers - do NOT
   * inline new behavior here.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $fieldItemList
   *   The field item list to introspect.
   *
   * @return array
   *   A flat map of {property_name: leaf_schema} for the first item.
   */
  private function composeField(FieldItemListInterface $fieldItemList): array {
    // Materialise an item so we can read property definitions.
    if ($fieldItemList->count() === 0) {
      $fieldItemList->appendItem([]);
    }
    $item = $fieldItemList->first();
    $itemDef = $item->getDataDefinition();

    $propSchemas = [];
    foreach ($itemDef->getPropertyDefinitions() as $propName => $propDef) {
      if ($propDef->isComputed() || $propDef->isInternal()) {
        continue;
      }
      $property = $item->get($propName);
      $propSchemas[$propName] = $this->serializer->normalize($property, 'json_schema');
    }

    return $propSchemas;
  }

}
