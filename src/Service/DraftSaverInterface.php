<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Interface for the draft saving service.
 *
 * Validates, builds, and saves a drafted node from LLM-produced field
 * values, on the session's own node: the first save creates it and stores
 * the reference on the session, every later save adds a new revision to
 * that same node.
 */
interface DraftSaverInterface {

  /**
   * Validates, builds, and saves a drafted node revision.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the draft. Its content type is the target bundle;
   *   its node reference (if any) names the node to revise instead of
   *   creating a new one; the first save writes the created node's id back
   *   onto the session.
   * @param array $fields
   *   The LLM-produced fields map, keyed by field machine name.
   *   Values are in the Drupal serialization format (arrays of
   *   items, e.g. [["value" => "Title"]]).
   * @param string|null $templateId
   *   The drafting template id whose resolved defaults are merged over
   *   $fields before saving, or NULL to skip the merge (drafts with no
   *   template snapshot).
   * @param int $version
   *   The draft version being saved, recorded in the revision log message
   *   when this save adds a revision to an existing node.
   *
   * @return array
   *   An array with 'nodeId' (string) and 'previewUrl' (string).
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   - 'invalid_bundle' (400) if the bundle does not exist (first save
   *     only).
   *   - 'forbidden' (403) if the user lacks create permission (first save)
   *     or update access on the session's node (later saves).
   *   - 'invalid_request' (400) if $templateId cannot be resolved.
   *   - 'invalid_payload' (400) if the entity builder rejects
   *     the payload.
   */
  public function save(AiEditorialSessionInterface $session, array $fields, ?string $templateId, int $version): array;

}
