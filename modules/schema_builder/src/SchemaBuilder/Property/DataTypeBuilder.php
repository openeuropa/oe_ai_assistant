<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder\Property;

use Drupal\schema_builder\Attribute\PropertySchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\PropertySchemaBuilderBase;
use Drupal\schema_builder\Context\PropertyContext;

/**
 * Gives every property the JSON type that matches its typed-data type.
 */
#[PropertySchemaBuilder(
  weight: -10,
)]
final class DataTypeBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    // Anything with no arithmetic or truth to it is carried as a string,
    // which is what the denormalizer accepts for dates, URIs and the rest.
    $schema['type'] = match ($context->propertyDefinition->getDataType()) {
      'boolean' => 'boolean',
      'integer', 'timestamp' => 'integer',
      'float', 'decimal' => 'number',
      default => 'string',
    };

    return $schema;
  }

}
