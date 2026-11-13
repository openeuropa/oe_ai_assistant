<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\schema_builder\Attribute\FieldSchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\FieldSchemaBuilderBase;
use Drupal\schema_builder\Context\FieldContext;

/**
 * States the values the chosen template pins on a field.
 *
 * We could merge a pinned value into the answer once it comes back, but
 * validation has run by then and what we build from it would go unchecked.
 * Stating it as a const keeps the value inside what gets validated, and the
 * model can't answer with anything else.
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

    // ReferenceBuilder has already replaced the columns of a reference item
    // with a choice of whole entities. Pinning a column back onto the item
    // would ask for a payload that no branch of the choice accepts.
    if (isset($schema['items']['oneOf'])) {
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

    // A default is stored the way the field holds it, a list of items with
    // their columns. Only the first item is pinned, since a pinned value
    // never needs more than one.
    $item = reset($default);
    foreach ($item as $property => $value) {
      $schema['items']['properties'][$property]['const'] = $value;
    }
    $schema['maxItems'] = 1;
    $schema['minItems'] = 1;

    return $schema;
  }

}
