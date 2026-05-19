<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Builds unsaved inline child entities from LLM-shaped JSON.
 *
 * Workaround for the gap documented in
 * CoreJsonSchemaTest::testDeserializeSilentlyDropsInlineParagraphs:
 * Drupal core 11.3.x silently drops inline child entity data when calling
 * $serializer->deserialize() on a parent with `entity_reference_revisions`
 * fields. This service walks the LLM's fields map, peels off any
 * `entity_reference_revisions` fields (the typical use case is paragraphs but
 * the mechanism is generic for any revisionable inline child entity),
 * denormalises each child entity (recursing into nested
 * `entity_reference_revisions` fields) into UNSAVED entity objects ready for
 * appendItem() onto the parent's field. The parent's
 * EntityReferenceRevisionsItem::preSave() chain then saves the whole tree
 * atomically. No orphan-child window.
 *
 * Reusable by the upcoming orchestrator + sub-agent flow (template-driven
 * drafting); keep the surface intentionally generic so per-slot creation
 * can call hydrateInto() one slot at a time.
 *
 * Cross-reference: drupal/default_content 2.0.x's importer uses essentially
 * the same recursive denormalize-without-save pattern; we ship a scoped
 * version here while the upstream gap remains open.
 */
class InlineEntityHydrator {

  public function __construct(
    private readonly SerializerInterface $serializer,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Splits a fields map into (parent fields, inline-entity fields).
   *
   * Inspects each field's storage definition to identify
   * `entity_reference_revisions` fields. Those get peeled off into a separate
   * map for post-deserialize handling; everything else stays for the parent's
   * regular deserialize. The mechanism is generic across any revisionable
   * inline child entity; the typical use case is paragraphs.
   *
   * @param array $fields
   *   The full fields map from the request.
   * @param string $entityTypeId
   *   The entity type whose field definitions to inspect.
   * @param string $bundle
   *   The bundle whose field definitions to inspect.
   *
   * @return array{0: array, 1: array<string, array{target_type: string, items: array}>}
   *   [parentFields (without inline-entity fields), inlineEntityFields (only
   *   the stripped fields, keyed by field name, each carrying the field's
   *   `target_type` setting alongside the raw items)].
   */
  public function splitInlineEntityFields(array $fields, string $entityTypeId, string $bundle): array {
    $defs = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    $parentFields = $fields;
    $inlineEntityFields = [];
    foreach ($fields as $name => $value) {
      if (!isset($defs[$name])) {
        continue;
      }
      if ($defs[$name]->getType() !== 'entity_reference_revisions') {
        continue;
      }
      $targetType = $defs[$name]->getFieldStorageDefinition()->getSetting('target_type');
      $inlineEntityFields[$name] = [
        'target_type' => $targetType,
        'items' => $value,
      ];
      unset($parentFields[$name]);
    }
    return [$parentFields, $inlineEntityFields];
  }

  /**
   * Builds inline child entities (unsaved) from LLM-shaped item arrays.
   *
   * Recursive: each item may itself contain nested
   * `entity_reference_revisions` fields. The inner entities are denormalized
   * first and appendItem'd onto the outer entity BEFORE returning, so the
   * outer entity's preSave chain saves them transactionally.
   *
   * @param array $items
   *   Array of item arrays in denormalize-input shape, e.g.
   *   `[{type: [{target_id: "text_block"}], field_text_body: [{value: "..."}]}, ...]`.
   * @param string $targetEntityType
   *   The entity type ID the items target (e.g. "paragraph"). Used to derive
   *   the entity class for $serializer->deserialize() and to look up nested
   *   field definitions for recursion.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Unsaved entities, ready for appendItem onto the parent's field.
   */
  public function buildInlineEntities(array $items, string $targetEntityType): array {
    $entityType = $this->entityTypeManager->getDefinition($targetEntityType);
    $bundleKey = $entityType->getKey('bundle');
    if (!$bundleKey) {
      throw new \InvalidArgumentException(sprintf(
        'Entity type "%s" has no bundle key; cannot build inline entities of an unbundled type.',
        $targetEntityType,
      ));
    }
    $entityClass = $entityType->getClass();

    $entities = [];
    foreach ($items as $i => $item) {
      if (!is_array($item)) {
        throw new \InvalidArgumentException(sprintf(
          'Inline entity item at index %d is not an array.',
          $i,
        ));
      }
      $bundle = $item[$bundleKey][0]['target_id'] ?? NULL;
      if ($bundle === NULL) {
        throw new \InvalidArgumentException(sprintf(
          'Inline entity item at index %d is missing %s[0][target_id].',
          $i,
          $bundleKey,
        ));
      }

      // Recurse into nested inline-entity fields BEFORE building this entity.
      [$ownFields, $nestedInlineEntityFields] = $this->splitInlineEntityFields(
        $item, $targetEntityType, $bundle,
      );

      // Drop the bundle key from the JSON we pass to denormalize: it's
      // already supplied via the outer key we inject below.
      unset($ownFields[$bundleKey]);

      $entityJson = json_encode(
        [$bundleKey => [['target_id' => $bundle]], ...$ownFields],
        JSON_THROW_ON_ERROR,
      );
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->serializer->deserialize(
        $entityJson,
        $entityClass,
        'json',
      );

      // Attach nested children (also unsaved) so they persist transactionally
      // when the outer entity's preSave runs.
      foreach ($nestedInlineEntityFields as $fieldName => $nestedInfo) {
        $nestedEntities = $this->buildInlineEntities(
          $nestedInfo['items'],
          $nestedInfo['target_type'],
        );
        foreach ($nestedEntities as $nested) {
          $entity->get($fieldName)->appendItem($nested);
        }
      }

      $entities[] = $entity;
    }
    return $entities;
  }

  /**
   * Convenience: split inline entities off, build them, attach to a parent.
   *
   * Returns the parent fields with inline-entity fields removed (for the
   * caller's own deserialize call). The inline child entities are
   * appendItem'd onto $parent's fields directly.
   *
   * @param array $fields
   *   The full LLM fields map.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $parent
   *   The parent entity (must already exist; typically just deserialized).
   * @param string $entityTypeId
   *   Entity type of the parent.
   * @param string $bundle
   *   Bundle of the parent.
   *
   * @return array
   *   The fields map with inline-entity fields stripped (for the caller's
   *   audit).
   */
  public function hydrateInto(array $fields, FieldableEntityInterface $parent, string $entityTypeId, string $bundle): array {
    [$nonInlineFields, $inlineEntityFields] = $this->splitInlineEntityFields(
      $fields, $entityTypeId, $bundle,
    );
    foreach ($inlineEntityFields as $fieldName => $info) {
      $children = $this->buildInlineEntities(
        $info['items'],
        $info['target_type'],
      );
      foreach ($children as $child) {
        $parent->get($fieldName)->appendItem($child);
      }
    }
    return $nonInlineFields;
  }

}
