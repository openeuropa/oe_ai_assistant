<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder\Property;

use Drupal\schema_api\Attribute\PropertySchemaBuilder;
use Drupal\schema_api\Builder\PropertySchemaBuilderBase;
use Drupal\schema_api\Context\PropertyContext;

/**
 * Reads what the settings of one field say about its own value.
 *
 * A setting is chosen per field, so it is the more specific statement and
 * runs after the constraints a field type declares once for all of them.
 */
#[PropertySchemaBuilder(
  weight: 10,
)]
final class FieldSettingsBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    $storage = $context->fieldDefinition->getFieldStorageDefinition();

    // The settings describe the value of the field, which lives in the main
    // property. Asking the storage for its name reaches the field types that
    // call it something other than "value".
    if ($context->propertyName !== $storage->getMainPropertyName()) {
      return $schema;
    }

    $settings = $storage->getSettings() + $context->fieldDefinition->getSettings();

    // With allowed_values_function set, the field works out its options at
    // run time and allowed_values is a stale copy of the last static list
    // nobody can edit. Publishing it would offer options the field rejects.
    if (!empty($settings['allowed_values']) && empty($settings['allowed_values_function'])) {
      $schema['enum'] = array_keys($settings['allowed_values']);
    }

    if (($settings['datetime_type'] ?? NULL) !== NULL) {
      $schema['format'] = $settings['datetime_type'] === 'date' ? 'date' : 'date-time';
    }

    return $schema;
  }

}
