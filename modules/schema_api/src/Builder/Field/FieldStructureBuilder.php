<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder\Field;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\schema_api\Attribute\FieldSchemaBuilder;
use Drupal\schema_api\Builder\FieldSchemaBuilderBase;
use Drupal\schema_api\Context\FieldContext;

/**
 * Describes a field as the list of items it is.
 */
#[FieldSchemaBuilder(
  weight: 0,
)]
final class FieldStructureBuilder extends FieldSchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(FieldContext $context, array $schema): ?array {
    $storage = $context->fieldDefinition->getFieldStorageDefinition();

    // Every field is a list, whatever its cardinality, because that is the
    // shape the entity denormalizer reads back.
    $schema = ['type' => 'array'] + $schema;

    $cardinality = $storage->getCardinality();
    if ($cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
      $schema['maxItems'] = $cardinality;
    }

    // The 'required' list only says the key has to be there, and an empty
    // array satisfies it. That then fails Drupal's own NotNull constraint on
    // save, so we ask for at least one item as well.
    if ($context->fieldDefinition->isRequired()) {
      $schema['minItems'] = 1;
    }

    return $schema;
  }

}
