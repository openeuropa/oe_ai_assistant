<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Core\Entity\EntityInterface;

/**
 * Reads the generated-draft history of an editorial session.
 *
 * Drafts live on the tool call that completed them in the persisted
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
   *   The number of versioned drafts stored on the transcript.
   */
  public function countDrafts(EntityInterface $session): int;

  /**
   * Lists the stored drafts with their provenance snapshots.
   *
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The session hosting the conversation.
   *
   * @return array
   *   One entry per draft, grouped so that revisions follow the draft they
   *   started from: {name: "Draft 2.1", label: "2.1", version: N,
   *   revisionOf: N|null, context: snapshot array}.
   */
  public function listDrafts(EntityInterface $session): array;

  /**
   * Returns the fields and template id for one stored draft version.
   *
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The session hosting the conversation.
   * @param int $version
   *   The draft version to look up (as returned by listDrafts()).
   *
   * @return array|null
   *   {fields: array, templateId: string|null, context: array|null}, or
   *   NULL if no stored draft carries that version. templateId is NULL
   *   when the draft's snapshot has no template.
   */
  public function getDraftContent(EntityInterface $session, int $version): ?array;

}
