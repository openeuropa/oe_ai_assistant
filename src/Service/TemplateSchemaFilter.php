<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;

/**
 * Prunes a composed entity schema to a drafting template's field sub-tree.
 */
class TemplateSchemaFilter implements TemplateSchemaFilterInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function filter(array $schema, AiDraftingTemplateInterface $template): array {
    return $this->pruneObject($schema, $template->getFields());
  }

  /**
   * {@inheritdoc}
   */
  public function splitIntoGroups(array $schema, AiDraftingTemplateInterface $template): array {
    $filtered = $this->filter($schema, $template);
    $properties = $filtered['properties'] ?? [];

    // Field labels for the content type, to label complex-reference groups.
    $labels = [];
    foreach ($this->entityFieldManager->getFieldDefinitions('node', $template->getContentType()) as $name => $definition) {
      $labels[$name] = (string) $definition->getLabel();
    }

    $mainFields = [];
    $referenceGroups = [];
    foreach ($properties as $fieldName => $fieldSchema) {
      $targetType = $fieldSchema['items']['x-targetType'] ?? NULL;
      if ($targetType !== NULL && !in_array($targetType, EntityJsonSchemaComposer::SIMPLE_REFERENCE_TARGETS, TRUE)) {
        $referenceGroups[] = [
          'groupId' => $fieldName,
          'label' => $labels[$fieldName] ?? $fieldName,
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
    if ($mainFields !== []) {
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
    foreach ($referenceGroups as $group) {
      $groups[] = $group;
    }
    return $groups;
  }

  /**
   * Prunes an object schema's properties to a template fields map.
   *
   * @param array $schema
   *   An `{type: "object", properties: {...}, required?: [...]}` schema.
   * @param array $templateFields
   *   A template fields map (machine name => field definition).
   *
   * @return array
   *   The pruned object schema.
   */
  private function pruneObject(array $schema, array $templateFields): array {
    $properties = $schema['properties'] ?? [];

    $kept = [];
    foreach ($templateFields as $fieldName => $templateField) {
      // Defensive: skip a template field the composer did not emit.
      if (!isset($properties[$fieldName])) {
        continue;
      }
      $kept[$fieldName] = $this->pruneField($properties[$fieldName], $templateField);
    }

    $result = [
      'type' => 'object',
      'properties' => $kept,
    ];
    $required = array_values(array_intersect($schema['required'] ?? [], array_keys($kept)));
    if ($required !== []) {
      $result['required'] = $required;
    }
    return $result;
  }

  /**
   * Restricts one field's schema to what the template allows.
   *
   * Leaf fields pass through unchanged. Paragraph and inline-reference fields
   * are narrowed to the bundles the template lists, and each of those to the
   * nested fields it names.
   *
   * @param array $fieldSchema
   *   The composed field schema.
   * @param array $templateField
   *   The matching template field definition.
   *
   * @return array
   *   The pruned field schema.
   */
  private function pruneField(array $fieldSchema, array $templateField): array {
    if (empty($templateField['items']) || !is_array($templateField['items'])) {
      return $fieldSchema;
    }

    $items = $fieldSchema['items'] ?? [];
    $targetType = $items['x-targetType'] ?? NULL;
    // Only fields that offer a choice of bundles can be narrowed per bundle;
    // anything else is left as-is.
    if ($targetType === NULL || !isset($items['oneOf'])) {
      return $fieldSchema;
    }

    $bundleKey = $this->entityTypeManager->getDefinition($targetType)->getKey('bundle');
    // Defensive, normally unreachable: the composer only emits oneOf for
    // bundled targets. Should it ever happen, leave the field whole instead of
    // breaking drafting.
    if (!$bundleKey) {
      return $fieldSchema;
    }

    // The same bundle may appear in multiple items; union their field names.
    $allowedFields = [];
    foreach ($templateField['items'] as $item) {
      $bundle = $item['bundle'] ?? NULL;
      if ($bundle === NULL) {
        continue;
      }
      $allowedFields[$bundle] = array_merge(
        $allowedFields[$bundle] ?? [],
        array_keys($item['fields'] ?? []),
      );
    }

    $variants = [];
    foreach ($items['oneOf'] as $variant) {
      $bundle = $variant['properties'][$bundleKey]['items']['properties']['target_id']['const'] ?? NULL;
      if ($bundle === NULL || !array_key_exists($bundle, $allowedFields)) {
        continue;
      }
      $variants[] = $this->pruneVariant($variant, $bundleKey, $allowedFields[$bundle]);
    }
    // No template bundle matched a variant (config drifted since the template
    // was saved): an empty oneOf matches nothing, keep the field whole.
    if ($variants === []) {
      return $fieldSchema;
    }
    $fieldSchema['items']['oneOf'] = $variants;

    return $fieldSchema;
  }

  /**
   * Prunes one bundle variant to its allowed nested fields plus discriminator.
   *
   * @param array $variant
   *   A composed bundle variant (`{type, properties, required?}`).
   * @param string $bundleKey
   *   The discriminator property name (the target type's bundle key).
   * @param string[] $allowedFields
   *   The nested field names the template keeps for this bundle.
   *
   * @return array
   *   The pruned variant.
   */
  private function pruneVariant(array $variant, string $bundleKey, array $allowedFields): array {
    // A bundle listed with no nested fields keeps the whole variant.
    // @todo Revisit whether to restrict to leaf fields instead, once we have
    //   observed how this behaves on large content types.
    if ($allowedFields === []) {
      return $variant;
    }

    $keep = array_fill_keys(array_merge($allowedFields, [$bundleKey]), TRUE);
    $properties = [];
    foreach ($variant['properties'] ?? [] as $name => $propSchema) {
      if (isset($keep[$name])) {
        $properties[$name] = $propSchema;
      }
    }
    $variant['properties'] = $properties;

    // Recompute required against kept fields; never list the discriminator.
    $required = array_values(array_diff(
      array_intersect($variant['required'] ?? [], array_keys($properties)),
      [$bundleKey],
    ));
    if ($required !== []) {
      $variant['required'] = $required;
    }
    else {
      unset($variant['required']);
    }

    return $variant;
  }

}
