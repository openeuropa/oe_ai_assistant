<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\schema_api\Attribute\FieldItemSchemaBuilder;
use Drupal\schema_api\Builder\FieldItemSchemaBuilderBase;
use Drupal\schema_api\Context\ItemContext;

/**
 * Narrows a reference to the bundles the chosen template asks for.
 *
 * A paragraph field tends to accept many more types than one piece of
 * writing uses, and the model may reach for any type left in the choice.
 * The template lists the types this draft is built from, so the rest are
 * dropped.
 */
#[FieldItemSchemaBuilder(
  field_type: 'entity_reference_revisions',
  weight: 60,
)]
final class TemplateBundlesBuilder extends FieldItemSchemaBuilderBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function build(ItemContext $context, array $schema): array {
    $template = $context->parameters['template'] ?? NULL;
    if (!$template instanceof TemplateIndex || empty($schema['oneOf'])) {
      return $schema;
    }

    $targetType = $schema['x-targetType'];
    $bundleKey = $this->entityTypeManager->getDefinition($targetType)->getKey('bundle');

    $kept = array_values(array_filter(
      $schema['oneOf'],
      static fn (array $variant): bool => $template->covers(
        $targetType,
        $variant['properties'][$bundleKey]['items']['properties']['target_id']['const'],
      ),
    ));

    // A template that names none of this field's bundles isn't saying
    // anything about it, so the full choice is left in place rather than
    // narrowed down to nothing.
    if ($kept !== []) {
      $schema['oneOf'] = $kept;
    }

    return $schema;
  }

}
