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
 * Recursively resolves referenced entities (paragraphs, media) so downstream
 * consumers (LLMs) know the full structure of a piece of content.
 */
class ContentTypeSchemaExtractor {

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

  private const BASE_FIELD_WHITELIST = ['title', 'body', 'status'];

  /**
   * Entity types whose fields are recursed into by default.
   *
   * Other referenced entity types (node, taxonomy_term, etc.) will still
   * appear in the schema with target type and bundle labels, but their
   * fields will not be expanded. This keeps the output compact enough
   * for LLM consumption.
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
   * Entity types to recurse into for the current extraction.
   *
   * @var string[]
   */
  private array $recursableEntityTypes = self::DEFAULT_RECURSABLE_ENTITY_TYPES;

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * Extracts the schema for the content type of the given node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to extract the schema for.
   * @param int $maxDepth
   *   Maximum recursion depth for references.
   * @param string[]|null $recursableEntityTypes
   *   Entity types to recurse into, or NULL for the default set.
   */
  public function extractFromNode(NodeInterface $node, int $maxDepth = 3, ?array $recursableEntityTypes = NULL): array {
    return $this->extract('node', $node->bundle(), $maxDepth, $recursableEntityTypes);
  }

  /**
   * Extracts the schema for a given entity type and bundle.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. 'node', 'paragraph').
   * @param string $bundle
   *   The bundle machine name.
   * @param int $maxDepth
   *   Maximum recursion depth for references.
   * @param string[]|null $recursableEntityTypes
   *   Entity types to recurse into, or NULL for the default set.
   *   Only these entity types will have their fields expanded; other
   *   references still appear with target type and bundle labels.
   */
  public function extract(string $entityTypeId, string $bundle, int $maxDepth = 3, ?array $recursableEntityTypes = NULL): array {
    $this->recursableEntityTypes = $recursableEntityTypes ?? self::DEFAULT_RECURSABLE_ENTITY_TYPES;

    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
    $label = $bundleInfo[$bundle]['label'] ?? $bundle;

    return [
      'contentType' => $bundle,
      'label' => (string) $label,
      'fields' => $this->extractFields($entityTypeId, $bundle, $maxDepth, []),
    ];
  }

  /**
   * Extracts field definitions for an entity type/bundle pair.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. 'node', 'paragraph').
   * @param string $bundle
   *   The bundle machine name.
   * @param int $depth
   *   Remaining recursion depth.
   * @param array $visited
   *   Already-visited "entityType:bundle" pairs for circular reference guard.
   *
   * @return array
   *   List of field schema arrays.
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
   * Includes configurable (bundle) fields and a whitelist of base fields.
   * Excludes computed fields and system base fields like uid, created, etc.
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
   */
  private function buildFieldSchema(
    string $fieldName,
    FieldDefinitionInterface $fieldDefinition,
    int $depth,
    array $visited,
  ): array {
    $storageDefinition = $fieldDefinition->getFieldStorageDefinition();
    $drupalType = $storageDefinition->getType();
    $schemaType = self::TYPE_MAP[$drupalType] ?? 'unknown';

    $schema = [
      'name' => $fieldName,
      'label' => (string) $fieldDefinition->getLabel(),
      'type' => $schemaType,
      'required' => $fieldDefinition->isRequired(),
      'cardinality' => $storageDefinition->getCardinality(),
      'description' => (string) ($fieldDefinition->getDescription() ?? ''),
      'constraints' => $this->extractConstraints($fieldDefinition, $schemaType),
    ];

    if ($schemaType === 'reference') {
      $schema['reference'] = $this->resolveReference($fieldDefinition, $depth, $visited);
    }

    return $schema;
  }

  /**
   * Extracts constraints from field and storage settings.
   */
  private function extractConstraints(FieldDefinitionInterface $fieldDefinition, string $schemaType): object|array {
    $storageDefinition = $fieldDefinition->getFieldStorageDefinition();
    $fieldSettings = $fieldDefinition->getSettings();
    $storageSettings = $storageDefinition->getSettings();
    $constraints = [];

    switch ($schemaType) {
      case 'string':
        $maxLength = $storageSettings['max_length'] ?? NULL;
        if ($maxLength) {
          $constraints['maxLength'] = (int) $maxLength;
        }
        break;

      case 'number':
        if (isset($fieldSettings['min']) && $fieldSettings['min'] !== '') {
          $constraints['min'] = $fieldSettings['min'];
        }
        if (isset($fieldSettings['max']) && $fieldSettings['max'] !== '') {
          $constraints['max'] = $fieldSettings['max'];
        }
        break;

      case 'select':
        $allowedValues = $storageSettings['allowed_values'] ?? [];
        if ($allowedValues) {
          $constraints['allowedValues'] = $allowedValues;
        }
        break;

      case 'image':
      case 'file':
        if (!empty($fieldSettings['file_extensions'])) {
          $constraints['fileExtensions'] = $fieldSettings['file_extensions'];
        }
        if (!empty($fieldSettings['max_filesize'])) {
          $constraints['maxFilesize'] = $fieldSettings['max_filesize'];
        }
        if (!empty($fieldSettings['max_resolution'])) {
          $constraints['maxResolution'] = $fieldSettings['max_resolution'];
        }
        if (!empty($fieldSettings['min_resolution'])) {
          $constraints['minResolution'] = $fieldSettings['min_resolution'];
        }
        break;

      case 'link':
        if (isset($fieldSettings['link_type'])) {
          $constraints['linkType'] = $fieldSettings['link_type'];
        }
        break;
    }

    // Return an empty object so JSON encodes as {} instead of [].
    return $constraints ?: new \stdClass();
  }

  /**
   * Resolves a reference field into target type and bundle information.
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

    // If target_bundles is NULL, all bundles are allowed.
    if ($targetBundles === NULL) {
      $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
      $targetBundles = array_keys($bundleInfo);
    }
    else {
      $targetBundles = array_values($targetBundles);
    }

    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($targetType);
    $resolvedBundles = [];

    foreach ($targetBundles as $targetBundle) {
      $bundleLabel = (string) ($bundleInfo[$targetBundle]['label'] ?? $targetBundle);
      $bundleData = ['label' => $bundleLabel];

      $visitKey = "$targetType:$targetBundle";
      $isRecursable = in_array($targetType, $this->recursableEntityTypes, TRUE);
      $isAtDepthLimit = $depth <= 1;
      $isCircular = in_array($visitKey, $visited, TRUE);

      // Only recurse into entity types on the allowlist.
      if ($isRecursable && !$isAtDepthLimit && !$isCircular) {
        $bundleData['fields'] = $this->extractFields(
          $targetType,
          $targetBundle,
          $depth - 1,
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
