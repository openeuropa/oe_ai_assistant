<?php

declare(strict_types=1);

namespace Drupal\schema_builder_test\SchemaBuilder;

use Drupal\schema_builder\Attribute\PropertySchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\PropertySchemaBuilderBase;
use Drupal\schema_builder\Context\PropertyContext;

/**
 * Stands in for a module that describes a field type schema_builder ignores.
 */
#[PropertySchemaBuilder(
  field_type: 'link',
  property_name: 'uri',
  weight: 50,
)]
final class LinkUriBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    $schema['format'] = 'uri';

    return $schema;
  }

}
