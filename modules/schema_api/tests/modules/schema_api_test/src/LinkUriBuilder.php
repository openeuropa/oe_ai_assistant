<?php

declare(strict_types=1);

namespace Drupal\schema_api_test;

use Drupal\schema_api\Attribute\PropertySchemaBuilder;
use Drupal\schema_api\Builder\PropertySchemaBuilderBase;
use Drupal\schema_api\Context\PropertyContext;

/**
 * Stands in for a module that describes a field type schema_api ignores.
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
