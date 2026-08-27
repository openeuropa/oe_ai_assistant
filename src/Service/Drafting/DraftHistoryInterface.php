<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Core\Entity\EntityInterface;

/**
 * Reads the generated-draft history of an editorial session.
 *
 * Drafts live as results on draft_content tool calls in the persisted
 * transcript; this service is the single reader used both to compute the
 * next version number and to answer the get_draft_history tool.
 */
interface DraftHistoryInterface {

  /**
   * Counts the drafts already stored for a session.
   *
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The session hosting the conversation.
   *
   * @return int
   *   The number of draft_content calls that carry a result.
   */
  public function countDrafts(EntityInterface $session): int;

  /**
   * Lists the stored drafts with their provenance snapshots.
   *
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The session hosting the conversation.
   *
   * @return array
   *   One entry per draft, in version order: {name: "Draft N", version: N,
   *   context: snapshot array or NULL for pre-provenance legacy results}.
   */
  public function listDrafts(EntityInterface $session): array;

  /**
   * Returns the drafted field values of one stored draft version.
   *
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The session hosting the conversation.
   * @param int $version
   *   The draft version to look up.
   *
   * @return array|null
   *   The field values keyed by field machine name, or NULL when the
   *   session has no draft with that version.
   */
  public function getDraftFields(EntityInterface $session, int $version): ?array;

}
