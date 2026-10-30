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
   * Numbers the draft about to be stored for a session.
   *
   * Centralises what countDrafts() otherwise leaves to each caller: the
   * next draft takes the version after every draft stored so far.
   *
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The session hosting the conversation.
   *
   * @return array
   *   {version: N}, the version following the drafts already stored.
   */
  public function nextVersion(EntityInterface $session): array;

  /**
   * Lists the stored drafts with their provenance snapshots.
   *
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The session hosting the conversation.
   *
   * @return array
   *   One entry per draft, in version order: {name: "Draft N", version: N,
   *   context: snapshot array}.
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
   *   {fields: array, templateId: string|null}, or NULL if no draft_content
   *   result carries that version. templateId is NULL when the draft's
   *   snapshot has no template.
   */
  public function getDraftContent(EntityInterface $session, int $version): ?array;

}
