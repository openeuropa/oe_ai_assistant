<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Provides the drafting field groups for a bundle, optionally template-pruned.
 */
interface DraftingSchemaProviderInterface {

  /**
   * Returns the drafting field groups for a bundle.
   *
   * When the template id resolves to a template for this bundle, the groups are
   * pruned to the template's fields; otherwise the full composed grouping is
   * returned.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. node).
   * @param string $bundle
   *   The bundle machine name.
   * @param string $templateId
   *   An ai_drafting_template id. When '', the latest template for the bundle
   *   is used, or the full schema if the bundle has no template.
   *
   * @return array
   *   Ordered groups, each with 'groupId', 'label', 'fieldNames', and
   *   'schemaSlice' keys.
   */
  public function groups(string $entityTypeId, string $bundle, string $templateId = ''): array;

}
