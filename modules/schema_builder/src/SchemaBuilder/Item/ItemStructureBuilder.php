<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder\Item;

use Drupal\schema_builder\Attribute\FieldItemSchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\FieldItemSchemaBuilderBase;
use Drupal\schema_builder\Context\ItemContext;

/**
 * Describes one item of a field as the object of columns it is.
 */
#[FieldItemSchemaBuilder(
  weight: 0,
)]
final class ItemStructureBuilder extends FieldItemSchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(ItemContext $context, array $schema): array {
    $required = [];
    foreach ($context->fieldDefinition->getItemDefinition()->getPropertyDefinitions() as $name => $property) {
      // A property another builder removed is no longer part of the item, so
      // it cannot be required either.
      if ($property->isRequired() && isset($schema['properties'][$name])) {
        $required[] = $name;
      }
    }

    // additionalProperties closes the item, so a reader learns that the
    // columns listed are all there are.
    $schema = ['type' => 'object'] + $schema + ['additionalProperties' => FALSE];

    if ($required !== []) {
      $schema['required'] = $required;
    }

    return $schema;
  }

}
