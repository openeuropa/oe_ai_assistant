<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder\Property;

use Drupal\schema_builder\Attribute\PropertySchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\PropertySchemaBuilderBase;
use Drupal\schema_builder\Context\PropertyContext;

/**
 * Lists the values a property accepts, from the Choice constraint.
 */
#[PropertySchemaBuilder(
  constraint: ['Choice', 'AllowedValues'],
)]
final class ChoiceBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    // AllowedValues is the subclass core puts on list fields, so we read
    // either name the same way.
    $options = $context->getConstraint('Choice') ?? $context->getConstraint('AllowedValues');

    // AllowedValues leaves 'choices' unset when the list is worked out at
    // run time. A builder that knows where the list comes from fills it.
    if (!empty($options['choices'])) {
      $schema['enum'] = array_values($options['choices']);
    }

    // With 'multiple' the property holds several values at once, so the
    // bounds count them rather than measuring one.
    if (!empty($options['multiple'])) {
      if (isset($options['min'])) {
        $schema['minItems'] = (int) $options['min'];
      }
      if (isset($options['max'])) {
        $schema['maxItems'] = (int) $options['max'];
      }
    }

    return $schema;
  }

}
