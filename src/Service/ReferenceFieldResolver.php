<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Resolves and validates entity reference host fields for lookup tools.
 */
class ReferenceFieldResolver {

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
  }

  /**
   * Resolves and validates a host field definition.
   *
   * @param string $entityTypeId
   *   The host entity type ID.
   * @param string $bundle
   *   The host bundle.
   * @param string $fieldName
   *   The host field machine name.
   * @param array<string> $expectedFieldTypes
   *   Allowed field types for the lookup.
   * @param string $expectedTargetEntityType
   *   The required target entity type.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The resolved field definition.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the field is missing or not compatible with the lookup.
   */
  public function resolveFieldDefinition(
    string $entityTypeId,
    string $bundle,
    string $fieldName,
    array $expectedFieldTypes,
    string $expectedTargetEntityType,
  ): FieldDefinitionInterface {
    $definitions = $this->entityFieldManager->getFieldDefinitions(
      $entityTypeId,
      $bundle,
    );

    $definition = $definitions[$fieldName] ?? NULL;
    if ($definition === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'The field "%s" is not defined on %s bundle "%s".',
        $fieldName,
        $entityTypeId,
        $bundle,
      ));
    }

    $fieldType = $definition->getType();
    if (!in_array($fieldType, $expectedFieldTypes, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'The field "%s" on %s bundle "%s" must have one of these field types: %s. Got "%s".',
        $fieldName,
        $entityTypeId,
        $bundle,
        implode(', ', $expectedFieldTypes),
        $fieldType,
      ));
    }

    $targetEntityType = $definition->getSetting('target_type');
    if ($targetEntityType !== $expectedTargetEntityType) {
      throw new \InvalidArgumentException(sprintf(
        'The field "%s" on %s bundle "%s" must target "%s". Got "%s".',
        $fieldName,
        $entityTypeId,
        $bundle,
        $expectedTargetEntityType,
        (string) $targetEntityType,
      ));
    }

    return $definition;
  }

  /**
   * Returns explicitly configured target bundles for a reference field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return array<string>
   *   The allowed target bundle IDs. An empty array means the field does not
   *   explicitly restrict bundles.
   */
  public function getAllowedTargetBundles(
    FieldDefinitionInterface $definition,
  ): array {
    $handlerSettings = $definition->getSetting('handler_settings') ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? [];

    $bundleIds = [];
    foreach ($targetBundles as $key => $value) {
      $bundleIds[] = is_string($key) ? $key : (string) $value;
    }
    $bundleIds = array_values(array_unique($bundleIds));

    if ($bundleIds === []) {
      return [];
    }

    $originalOrder = array_flip($bundleIds);
    $dragDrop = is_array($handlerSettings['target_bundles_drag_drop'] ?? NULL)
      ? $handlerSettings['target_bundles_drag_drop']
      : [];

    usort($bundleIds, static function (string $left, string $right) use ($dragDrop, $originalOrder): int {
      $leftWeight = isset($dragDrop[$left]['weight'])
        ? (int) $dragDrop[$left]['weight']
        : $originalOrder[$left];
      $rightWeight = isset($dragDrop[$right]['weight'])
        ? (int) $dragDrop[$right]['weight']
        : $originalOrder[$right];

      if ($leftWeight !== $rightWeight) {
        return $leftWeight <=> $rightWeight;
      }

      return $originalOrder[$left] <=> $originalOrder[$right];
    });

    return $bundleIds;
  }

}
