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
   *   skip the merge entirely — used by the plain save path and by legacy
   *   pre-provenance drafts with no template snapshot.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The unsaved node, with inline child entities attached.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   - 'invalid_bundle' (400) if the bundle does not exist.
   *   - 'forbidden' (403) if the user lacks create permission for the bundle.
   *   - 'invalid_request' (400) if $templateId cannot be resolved (deleted,
   *     disabled, or targets a different bundle).
   *   - 'invalid_payload' (400) if the entity builder rejects the merged
   *     payload.
   */
  public function assemble(string $bundle, array $fields, ?string $templateId): ContentEntityInterface;

}
