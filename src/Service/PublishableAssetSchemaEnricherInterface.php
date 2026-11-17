<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Annotates the drafting schema with a session's attachable assets.
 *
 * The composed schema already exposes a content type's media reference
 * fields by name, but it cannot say which media ids the LLM may legally
 * place there: that depends on the editorial session, which the composer is
 * deliberately unaware of. This enricher post-processes the field groups to
 * pin each publishable-asset target field to the session's own asset ids, so
 * the model chooses from a closed list instead of guessing. The save-side
 * guard enforces the same rule; this is the advisory half.
 */
interface PublishableAssetSchemaEnricherInterface {

  /**
   * Pins the session's publishable-asset ids onto the matching schema fields.
   *
   * @param array $groups
   *   The field groups from DraftingSchemaProviderInterface::groups(), each
   *   with a 'schemaSlice' carrying the per-field schemas.
   * @param string $entityTypeId
   *   The drafted entity type id (e.g. node).
   * @param string $bundle
   *   The drafted bundle machine name.
   * @param int[] $assetIds
   *   The media ids of the session's publishable assets. An empty list marks
   *   the fields as having no attachable asset rather than pinning an enum.
   *
   * @return array
   *   The groups with the publishable-asset target fields annotated. Groups
   *   that hold no such field are returned unchanged.
   */
  public function enrich(array $groups, string $entityTypeId, string $bundle, array $assetIds): array;

}
