<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\schema_api\Attribute\FieldSchemaBuilder;
use Drupal\schema_api\Builder\FieldSchemaBuilderBase;
use Drupal\schema_api\Context\FieldContext;

/**
 * States the values the chosen template pins on a field.
 *
 * Merging a default into the answer after the fact leaves the document that
 * is finally built unchecked, because validation already ran. Saying the
 * value in the schema puts it inside what is validated, and a model that
 * writes around it cannot contradict it.
 */
#[FieldSchemaBuilder(
  weight: 40,
)]
final class TemplateDefaultsBuilder extends FieldSchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(FieldContext $context, array $schema): ?array {
    $template = $context->parameters['template'] ?? NULL;
    if (!$template instanceof TemplateIndex) {
      return $schema;
    }

    $default = $template->defaultFor(
      $context->entityTypeId,
      $context->bundle,
      $context->fieldDefinition->getName(),
    );
    if ($default === NULL) {
      return $schema;
    }

    // A default is stored as the field would hold it, a list of items each
    // holding its columns. One item is all a pinned value ever needs.
    $item = reset($default);
    foreach ($item as $property => $value) {
      $schema['items']['properties'][$property]['const'] = $value;
    }
    $schema['maxItems'] = 1;
    $schema['minItems'] = 1;

    return $schema;
  }

}
