<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder\Item;

use Drupal\schema_builder\Attribute\FieldItemSchemaBuilder;
use Drupal\schema_builder\SchemaBuilder\FieldItemSchemaBuilderBase;
use Drupal\schema_builder\Context\ItemContext;
use Drupal\schema_builder\SchemaBuilderInterface;

/**
 * Describes the entity an entity_reference_revisions field points at.
 *
 * This field type owns its target. A paragraph is created with its parent
 * and saved with it, so one payload can carry the whole thing. A plain
 * entity_reference points at an entity with a life of its own, found or
 * created elsewhere, and there the identifier is all we can describe.
 */
#[FieldItemSchemaBuilder(
  field_type: 'entity_reference_revisions',
  weight: 50,
)]
final class ReferenceBuilder extends FieldItemSchemaBuilderBase {

  public function __construct(
    private readonly SchemaBuilderInterface $schemaApi,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function build(ItemContext $context, array $schema): array {
    $storage = $context->fieldDefinition->getFieldStorageDefinition();
    $targetType = $storage->getSetting('target_type');
    $bundles = $context->fieldDefinition->getSetting('handler_settings')['target_bundles'] ?? NULL;

    // A field with no bundles named accepts every bundle of the target type,
    // which is too wide to describe. Its identifier stands on its own.
    if ($targetType === NULL || empty($bundles)) {
      return $schema;
    }

    $variants = [];
    foreach (array_keys($bundles) as $bundle) {
      // SchemaBuilder answers with nothing when this bundle is already being
      // walked, which is how a reference back to an ancestor ends.
      $variant = $this->schemaApi->get($targetType, $bundle, $context->parameters);
      if ($variant !== []) {
        $variants[] = $variant;
      }
    }

    if ($variants === []) {
      return $schema;
    }

    // The columns of the reference itself are no longer what is asked for,
    // since the payload now carries the entity rather than a pointer to one.
    unset($schema['properties'], $schema['required'], $schema['additionalProperties']);
    $schema['oneOf'] = $variants;
    $schema['x-targetType'] = $targetType;

    return $schema;
  }

}
