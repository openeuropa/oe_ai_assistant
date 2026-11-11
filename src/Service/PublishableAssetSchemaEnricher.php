<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Pins a session's attachable assets onto the drafting schema's media fields.
 *
 * Works on the grouped output of DraftingSchemaProvider: for every content
 * type field that can hold a publishable asset (a media reference whose
 * allowed bundles include ai_publishable_asset) it rewrites the field's
 * target_id schema to an enum of the session's own asset ids, so the LLM
 * picks a real session asset instead of an arbitrary media id. The composer
 * stays session-agnostic; this is a post-composition pass, like
 * TemplateSchemaFilter.
 */
class PublishableAssetSchemaEnricher implements PublishableAssetSchemaEnricherInterface {

  /**
   * The publishable-asset media bundle an attachable field must accept.
   */
  private const string ASSET_BUNDLE = 'ai_publishable_asset';

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enrich(array $groups, string $entityTypeId, string $bundle, array $assetIds): array {
    $attachableFields = $this->attachableFieldNames($entityTypeId, $bundle);
    if ($attachableFields === []) {
      return $groups;
    }

    // Normalise to integers once; the enum is emitted as integers to match
    // the composer's target_id type.
    $assetIds = array_values(array_map(static fn ($id): int => (int) $id, $assetIds));

    foreach ($groups as &$group) {
      $properties = &$group['schemaSlice']['properties'];
      if (!is_array($properties)) {
        continue;
      }
      foreach ($properties as $fieldName => &$fieldSchema) {
        if (!isset($attachableFields[$fieldName])) {
          continue;
        }
        $fieldSchema = $this->annotateField($fieldSchema, $assetIds);
      }
      // Release the inner reference before the next group iteration.
      unset($fieldSchema);
    }
    unset($group);

    return $groups;
  }

  /**
   * Annotates one field schema with the attachable asset ids.
   *
   * The field schema is the composer's array wrapper
   * {type: array, items: {properties: {target_id: ...}, x-targetType}}. The
   * enum and the editor-facing note are placed on items.properties.target_id,
   * the leaf the LLM fills.
   *
   * @param array $fieldSchema
   *   The per-field schema to annotate.
   * @param int[] $assetIds
   *   The session's publishable-asset ids.
   *
   * @return array
   *   The annotated field schema.
   */
  private function annotateField(array $fieldSchema, array $assetIds): array {
    if (!isset($fieldSchema['items']['properties']['target_id'])
      || !is_array($fieldSchema['items']['properties']['target_id'])
    ) {
      return $fieldSchema;
    }

    $targetId = &$fieldSchema['items']['properties']['target_id'];
    if ($assetIds === []) {
      // No assets uploaded to the session: tell the model there is nothing to
      // attach rather than leaving an open integer it might hallucinate.
      $targetId['description'] = 'No publishable assets are available on this '
        . 'editorial session; leave this field empty.';
    }
    else {
      $targetId['enum'] = $assetIds;
      $targetId['description'] = 'Only a media id from this list may be '
        . 'attached: these are the publishable assets uploaded to this '
        . 'editorial session. Leave the field empty to attach none.';
    }
    unset($targetId);

    return $fieldSchema;
  }

  /**
   * Finds the content type fields that can hold a publishable asset.
   *
   * A field qualifies when it is an entity reference to media whose allowed
   * target bundles include ai_publishable_asset, or are unrestricted (NULL),
   * in which case every media bundle - the publishable one among them - is
   * allowed.
   *
   * @param string $entityTypeId
   *   The drafted entity type id.
   * @param string $bundle
   *   The drafted bundle machine name.
   *
   * @return array<string, true>
   *   A set of qualifying field machine names, keyed for O(1) lookup.
   */
  private function attachableFieldNames(string $entityTypeId, string $bundle): array {
    $names = [];
    $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    foreach ($definitions as $name => $definition) {
      if ($this->isPublishableAssetField($definition)) {
        $names[$name] = TRUE;
      }
    }
    return $names;
  }

  /**
   * Checks whether a field can reference a publishable asset.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition to test.
   *
   * @return bool
   *   TRUE when the field references media and may target the publishable
   *   asset bundle.
   */
  private function isPublishableAssetField(FieldDefinitionInterface $definition): bool {
    if (!in_array($definition->getType(), ['entity_reference', 'file', 'image'], TRUE)) {
      return FALSE;
    }
    if ($definition->getFieldStorageDefinition()->getSetting('target_type') !== 'media') {
      return FALSE;
    }
    $targetBundles = $definition->getSetting('handler_settings')['target_bundles'] ?? NULL;
    // NULL means every media bundle is allowed, which includes the asset one.
    return $targetBundles === NULL || in_array(self::ASSET_BUNDLE, $targetBundles, TRUE);
  }

}
