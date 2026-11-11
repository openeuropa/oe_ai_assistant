<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder\Property;

use Drupal\schema_builder\Attribute\PropertySchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\PropertySchemaBuilderBase;
use Drupal\schema_builder\Context\PropertyContext;

/**
 * Bounds the length of a string from the Length constraint.
 */
#[PropertySchemaBuilder(
  constraint: 'Length',
)]
final class LengthBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    $options = $context->getConstraint('Length');

    if (isset($options['min'])) {
      $schema['minLength'] = (int) $options['min'];
    }
    if (isset($options['max'])) {
      $schema['maxLength'] = (int) $options['max'];
    }

    return $schema;
  }

}
