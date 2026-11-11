<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder\Property;

use Drupal\schema_api\Attribute\PropertySchemaBuilder;
use Drupal\schema_api\Builder\PropertySchemaBuilderBase;
use Drupal\schema_api\Context\PropertyContext;

/**
 * Bounds how many values a property holds, from the Count constraint.
 */
#[PropertySchemaBuilder(
  constraint: 'Count',
)]
final class CountBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    $options = $context->getConstraint('Count');

    if (isset($options['min'])) {
      $schema['minItems'] = (int) $options['min'];
    }
    if (isset($options['max'])) {
      $schema['maxItems'] = (int) $options['max'];
    }

    return $schema;
  }

}
