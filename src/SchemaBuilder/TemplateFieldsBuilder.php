<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\schema_api\Attribute\FieldSchemaBuilder;
use Drupal\schema_api\Builder\FieldSchemaBuilderBase;
use Drupal\schema_api\Context\FieldContext;

/**
 * Narrows a bundle to the fields the chosen template asks for.
 *
 * A bundle describes far more than any one piece of writing needs, and every
 * field left in is one more thing a model is invited to invent. The template
 * is the editor's answer to what this draft is about, so nothing outside it
 * is described.
 */
#[FieldSchemaBuilder(
  weight: -40,
)]
final class TemplateFieldsBuilder extends FieldSchemaBuilderBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function build(FieldContext $context, array $schema): ?array {
    $template = $context->parameters['template'] ?? NULL;
    if (!$template instanceof TemplateIndex) {
      return $schema;
    }

    // A bundle the template says nothing about is described in full, which
    // is what a reference into an undeclared paragraph type needs.
    if (!$template->covers($context->entityTypeId, $context->bundle)) {
      return $schema;
    }

    // The bundle field names which bundle a payload is, which is how the
    // denormalizer picks an entity class and how a reader tells the variants
    // of a reference apart. No template needs to ask for it.
    $bundleKey = $this->entityTypeManager
      ->getDefinition($context->entityTypeId)
      ->getKey('bundle');
    if ($bundleKey === $context->fieldDefinition->getName()) {
      return $schema;
    }

    return $template->wants($context->entityTypeId, $context->bundle, $context->fieldDefinition->getName())
      ? $schema
      : NULL;
  }

}
