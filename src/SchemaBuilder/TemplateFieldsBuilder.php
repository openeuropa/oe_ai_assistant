<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\schema_builder\Attribute\FieldSchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\FieldSchemaBuilderBase;
use Drupal\schema_builder\Context\FieldContext;

/**
 * Narrows a bundle to the fields the chosen template asks for.
 *
 * A bundle has far more fields than any one piece of writing needs, and
 * every field left in is one more the model may invent a value for. The
 * template is where the editor said what this draft covers, so anything it
 * doesn't name is dropped.
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

    // Keep every field of a bundle the template says nothing about. A
    // template can name a reference field without listing the paragraph
    // types behind it, and dropping their fields would leave them empty.
    if (!$template->covers($context->entityTypeId, $context->bundle)) {
      return $schema;
    }

    // Some fields are kept whatever the template says, because another
    // builder states their value and a template has no reason to ask for
    // one. The bundle field is how the denormalizer picks an entity class
    // and how a reader tells the variants of a reference apart.
    $bundleKey = $this->entityTypeManager
      ->getDefinition($context->entityTypeId)
      ->getKey('bundle');
    $pinned = array_merge([$bundleKey], DraftableFieldsBuilder::PINNED);
    if (in_array($context->fieldDefinition->getName(), $pinned, TRUE)) {
      return $schema;
    }

    return $template->wants($context->entityTypeId, $context->bundle, $context->fieldDefinition->getName())
      ? $schema
      : NULL;
  }

}
