<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Validates, merges template defaults, and builds an unsaved draft node.
 *
 * Shared by DraftSaver::save() and DraftingPlugin::preview() so both go
 * through one seam and cannot drift from each other.
 */
interface DraftAssemblerInterface {

  /**
   * Validates, resolves template defaults, and builds an unsaved node.
   *
   * @param string $bundle
   *   The content type bundle machine name (e.g. "oe_news").
   * @param array $fields
   *   The LLM-produced fields map, keyed by field machine name. Values are
   *   in the Drupal serialization format (arrays of items).
   * @param string|null $templateId
   *   The drafting template id whose resolved defaults are merged under
   *   $fields (a drafted value always wins on a key collision), or NULL to
   *   skip the merge entirely (legacy pre-provenance drafts with no template
   *   snapshot).
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $existingNode
   *   NULL (default) to build and return a brand-new unsaved node, checking
   *   the bundle's create permission. Pass an already-saved node to instead
   *   check update access on it and return that same node (mutated) with the
   *   merged field values applied in place, ready for a revision save.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The unsaved (or updated) node, with inline child entities attached.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   - 'invalid_bundle' (400) if the bundle does not exist ($existingNode
   *     is NULL only).
   *   - 'forbidden' (403) if the user lacks create permission for the bundle
   *     ($existingNode is NULL), or update access on $existingNode.
   *   - 'invalid_request' (400) if $templateId cannot be resolved (deleted,
   *     disabled, or targets a different bundle).
   *   - 'invalid_payload' (400) if the entity builder rejects the merged
   *     payload.
   */
  public function assemble(string $bundle, array $fields, ?string $templateId, ?ContentEntityInterface $existingNode = NULL): ContentEntityInterface;

}
