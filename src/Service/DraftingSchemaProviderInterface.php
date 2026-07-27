<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;

/**
 * Provides the drafting field groups for a bundle, optionally template-pruned.
 */
interface DraftingSchemaProviderInterface {

  /**
   * Returns the drafting field groups for a bundle.
   *
   * The template resolved by resolveTemplate() prunes the groups to its
   * fields; the full composed grouping is returned when no template applies.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. node).
   * @param string $bundle
   *   The bundle machine name.
   * @param string|null $templateId
   *   An ai_drafting_template id, or NULL to auto-select.
   *
   * @return array
   *   Ordered groups, each with 'groupId', 'label', 'fieldNames', and
   *   'schemaSlice' keys.
   *
   * @throws \InvalidArgumentException
   *   When an explicitly given template id cannot be used.
   */
  public function groups(string $entityTypeId, string $bundle, ?string $templateId = NULL): array;

  /**
   * Resolves the drafting template to use for a bundle.
   *
   * Templates only apply to nodes. An explicit id must exist, be enabled, and
   * target the bundle. Without an id, the latest enabled template for the
   * bundle is used ("latest" is the highest id; config has no timestamps).
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. node).
   * @param string $bundle
   *   The bundle machine name.
   * @param string|null $templateId
   *   An ai_drafting_template id, or NULL to auto-select.
   *
   * @return \Drupal\oe_ai_assistant\AiDraftingTemplateInterface|null
   *   The template, or NULL when none applies (full schema).
   *
   * @throws \InvalidArgumentException
   *   When an explicitly given template id cannot be used.
   */
  public function resolveTemplate(string $entityTypeId, string $bundle, ?string $templateId = NULL): ?AiDraftingTemplateInterface;

}
