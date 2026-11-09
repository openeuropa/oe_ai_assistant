<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Bundle;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Defines the interface for a session that drafts content.
 */
interface ContentCreationSessionInterface extends AiEditorialSessionInterface {

  /**
   * The entity type a session drafts for.
   */
  public const string ENTITY_TYPE_ID = 'node';

  /**
   * Returns the thread that holds the drafts of this session.
   *
   * No agent runs on this thread, so a draft never reaches a model through
   * the conversation it replays. Its rows carry the session reference.
   * deleteConversation() therefore deletes them too.
   */
  public function draftsThread(): string;

  /**
   * Returns the tone the editor selected.
   *
   * @return array|null
   *   The tone id, label and prompt, or NULL when the editor selected none.
   *
   * @throws \InvalidArgumentException
   *   When the stored tone is not one the bundle can draft with.
   */
  public function getTone(): ?array;

  /**
   * Returns the drafting template the editor selected.
   *
   * @return array|null
   *   The template id and label, or NULL without a template.
   *
   * @throws \InvalidArgumentException
   *   When the stored template is not one the bundle can draft with.
   */
  public function getTemplate(): ?array;

  /**
   * Returns the documents the editor attached as background.
   *
   * @return array
   *   The descriptors, each {id, title, category, status, filename, summary,
   *   meta, extract}. The extract is the full text when the pipeline produced
   *   one, NULL otherwise.
   */
  public function getDocuments(): array;

  /**
   * Returns the schema groups a new draft must match.
   *
   * @return array
   *   The groups, each with groupId, label, fieldNames and schemaSlice, in
   *   drafting order.
   */
  public function getFieldGroups(): array;

  /**
   * Counts the drafts already stored for this session.
   */
  public function countDrafts(): int;

  /**
   * Numbers the draft about to be stored.
   *
   * @return int
   *   The version following the drafts already stored.
   */
  public function nextVersion(): int;

  /**
   * Lists the stored drafts with their provenance snapshots.
   *
   * A run that failed stores no field, and is not a draft the editor can
   * open, so it is left out.
   *
   * @return array
   *   One entry per draft, in version order: {name: "Draft N", version: N,
   *   context: snapshot array}.
   */
  public function listDrafts(): array;

  /**
   * Returns the fields and template id of one stored draft.
   *
   * @param int $version
   *   The draft version to look up, as listDrafts() reports it.
   *
   * @return array|null
   *   {fields: array, templateId: string|null}, or NULL when no draft carries
   *   that version. The template id is NULL when the draft's snapshot has no
   *   template.
   */
  public function getDraftContent(int $version): ?array;

  /**
   * Returns the whole record one drafting run stored.
   *
   * A run that failed stores a record too, so this reports what happened to a
   * version the editor cannot open. Use getDraftContent() to read a draft.
   *
   * @param int $version
   *   The version the run drafted.
   *
   * @return array|null
   *   {version, run, status, context, groups, fields, error}, or NULL when no
   *   run carries that version.
   */
  public function getDraftRun(int $version): ?array;

}
