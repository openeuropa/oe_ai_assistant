<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Reads what a session drafts with, and renders it for a prompt.
 *
 * The agents that prompt a model and the tools that answer the model both ask
 * what the editor set up, so the reading is here rather than in either. The
 * rendering is here as well, because the chat agent and the group drafters
 * inject the same material and are plugins of different types.
 */
interface DraftingBriefInterface {

  /**
   * The entity type a session drafts for.
   */
  public const string ENTITY_TYPE_ID = 'node';

  /**
   * The tone the editor selected.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to read.
   *
   * @return array|null
   *   The tone id, label and prompt, or NULL when no tone is selected.
   *
   * @throws \InvalidArgumentException
   *   When the stored tone is not one the bundle can draft with.
   */
  public function tone(AiEditorialSessionInterface $session): ?array;

  /**
   * The drafting template the editor selected.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to read.
   *
   * @return array|null
   *   The template id and label, or NULL without a template.
   *
   * @throws \InvalidArgumentException
   *   When the stored template is not one the bundle can draft with.
   */
  public function template(AiEditorialSessionInterface $session): ?array;

  /**
   * The documents the editor attached as background.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to read.
   *
   * @return array
   *   The descriptors, each {id, title, category, status, filename, summary,
   *   meta, extract}. The extract is the full text when the pipeline produced
   *   one, NULL otherwise.
   */
  public function documents(AiEditorialSessionInterface $session): array;

  /**
   * The schema groups a new draft is written against.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to read.
   *
   * @return array
   *   The groups, each with groupId, label, fieldNames and schemaSlice, in
   *   drafting order.
   */
  public function groups(AiEditorialSessionInterface $session): array;

}
