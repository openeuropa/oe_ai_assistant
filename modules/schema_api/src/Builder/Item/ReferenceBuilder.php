<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder\Item;

use Drupal\schema_api\Attribute\FieldItemSchemaBuilder;
use Drupal\schema_api\Builder\FieldItemSchemaBuilderBase;
use Drupal\schema_api\Context\ItemContext;
use Drupal\schema_api\SchemaApiInterface;

/**
 * Describes what a reference points at, instead of only its identifier.
 *
 * Without this an item of a reference field says no more than "an integer",
 * which a reader cannot act on. Walking into each bundle the field accepts
 * gives a choice between whole entities.
 */
#[FieldItemSchemaBuilder(
  field_type: ['entity_reference', 'entity_reference_revisions'],
  weight: 50,
)]
final class ReferenceBuilder extends FieldItemSchemaBuilderBase {

  public function __construct(
    private readonly SchemaApiInterface $schemaApi,
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
      // SchemaApi answers with nothing when this bundle is already being
      // walked, which is how a reference back to an ancestor ends.
      $variant = $this->schemaApi->get($targetType, $bundle);
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
