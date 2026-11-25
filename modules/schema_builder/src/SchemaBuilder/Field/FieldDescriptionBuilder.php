<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder\Field;

use Drupal\schema_builder\Attribute\FieldSchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\FieldSchemaBuilderBase;
use Drupal\schema_builder\Context\FieldContext;

/**
 * Tells the reader of the schema what the field is for.
 */
#[FieldSchemaBuilder(
  weight: 0,
)]
final class FieldDescriptionBuilder extends FieldSchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(FieldContext $context, array $schema): ?array {
    // Many fields carry no description, and the label is the next best thing
    // a reader has to go on.
    $description = (string) ($context->fieldDefinition->getDescription()
      ?: $context->fieldDefinition->getLabel());

    if ($description !== '') {
      $schema['description'] = $description;
    }

    return $schema;
  }

}
