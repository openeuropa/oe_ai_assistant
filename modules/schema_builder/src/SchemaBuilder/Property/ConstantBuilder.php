<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder\Property;

use Drupal\schema_builder\Attribute\PropertySchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\PropertySchemaBuilderBase;
use Drupal\schema_builder\Context\PropertyContext;

/**
 * Pins a property to one value, from the constraints that fix it.
 */
#[PropertySchemaBuilder(
  constraint: ['IdenticalTo', 'FixedValue'],
)]
final class ConstantBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    foreach (['IdenticalTo', 'FixedValue'] as $constraint) {
      $options = $context->getConstraint($constraint);
      if (isset($options['value'])) {
        $schema['const'] = $options['value'];
        break;
      }
    }

    return $schema;
  }

}
