<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\schema_builder\Attribute\FieldItemSchemaBuilder;
use Drupal\schema_builder\Context\ItemContext;
use Drupal\schema_builder\SchemaBuilder\FieldItemSchemaBuilderBase;

/**
 * Drops the text format column of a formatted-text item.
 *
 * Which formats an editor may write in differs from one account to the next,
 * so a schema that lists them is account-dependent and cannot be cached. A
 * model that picks one can also pick a format the field restricts, and the
 * value it sends stops TextFormatResolver filling the right one on save.
 */
#[FieldItemSchemaBuilder(
  weight: 10,
)]
final class TextFormatBuilder extends FieldItemSchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(ItemContext $context, array $schema): array {
    foreach ($context->fieldDefinition->getItemDefinition()->getPropertyDefinitions() as $name => $property) {
      // Matching the data type covers every field type that stores formatted
      // text, contrib ones included.
      if ($property->getDataType() !== 'filter_format') {
        continue;
      }

      unset($schema['properties'][$name]);

      if (isset($schema['required'])) {
        $required = array_values(array_diff($schema['required'], [$name]));
        $schema['required'] = $required;
        if ($required === []) {
          unset($schema['required']);
        }
      }
    }

    return $schema;
  }

}
