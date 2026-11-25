<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder\Property;

use Drupal\schema_builder\Attribute\PropertySchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\PropertySchemaBuilderBase;
use Drupal\schema_builder\Context\PropertyContext;

/**
 * Bounds a number from the Range and PositiveOrZero constraints.
 */
#[PropertySchemaBuilder(
  constraint: ['Range', 'PositiveOrZero'],
)]
final class RangeBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    $options = $context->getConstraint('Range') ?? [];
    if (isset($options['min'])) {
      $schema['minimum'] = $options['min'];
    }
    if (isset($options['max'])) {
      $schema['maximum'] = $options['max'];
    }

    // PositiveOrZero says the same thing as a Range with a min of nought,
    // and a field may carry it instead.
    if ($context->getConstraint('PositiveOrZero') !== NULL && !isset($schema['minimum'])) {
      $schema['minimum'] = 0;
    }

    return $schema;
  }

}
