<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder\Item;

use Drupal\schema_builder\Attribute\FieldItemSchemaBuilder;
use Drupal\schema_builder\Context\ItemContext;
use Drupal\schema_builder\SchemaBuilder\FieldItemSchemaBuilderBase;

/**
 * Drops the columns of an item that nothing may write to.
 *
 * A computed property is worked out on read and an internal one belongs to
 * Drupal, so a value offered for either is thrown away. A serialized column
 * is worse: the generic field denormalizer refuses a string for one and
 * throws, which loses the whole draft.
 */
#[FieldItemSchemaBuilder(
  weight: -100,
)]
final class InternalPropertyBuilder extends FieldItemSchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(ItemContext $context, array $schema): array {
    $serialized = $this->serializedColumns($context);

    foreach ($context->fieldDefinition->getItemDefinition()->getPropertyDefinitions() as $name => $property) {
      if ($property->isComputed() || $property->isInternal() || in_array($name, $serialized, TRUE)) {
        unset($schema['properties'][$name]);
      }
    }

    return $schema;
  }

  /**
   * Names the columns of the item that are stored serialized.
   *
   * The field type declares them, which is where core's denormalizer reads
   * them too, so a contrib field type that serializes a column of its own
   * is covered without being named here.
   */
  private function serializedColumns(ItemContext $context): array {
    $storage = $context->fieldDefinition->getFieldStorageDefinition();
    $itemClass = $context->fieldDefinition->getItemDefinition()->getClass();
    $columns = $itemClass::schema($storage)['columns'] ?? [];

    return array_keys(array_filter(
      $columns,
      static fn (array $column): bool => !empty($column['serialize']),
    ));
  }

}
