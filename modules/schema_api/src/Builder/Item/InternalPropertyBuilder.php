<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder\Item;

use Drupal\schema_api\Attribute\FieldItemSchemaBuilder;
use Drupal\schema_api\Builder\FieldItemSchemaBuilderBase;
use Drupal\schema_api\Context\ItemContext;

/**
 * Drops the columns of an item that nothing may write to.
 *
 * A computed property is worked out on read and an internal one belongs to
 * Drupal. Offering either invites a value that the denormalizer discards.
 */
#[FieldItemSchemaBuilder(
  weight: -100,
)]
final class InternalPropertyBuilder extends FieldItemSchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(ItemContext $context, array $schema): array {
    foreach ($context->fieldDefinition->getItemDefinition()->getPropertyDefinitions() as $name => $property) {
      if ($property->isComputed() || $property->isInternal()) {
        unset($schema['properties'][$name]);
      }
    }

    return $schema;
  }

}
