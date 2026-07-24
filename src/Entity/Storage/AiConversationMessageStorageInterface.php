<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity\Storage;

use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Defines the storage handler for ai_conversation_message entities.
 *
 * Adds host-scoped queries so listing a conversation by its host entity lives
 * in one place, reused by the conversation store and the messages endpoint.
 */
interface AiConversationMessageStorageInterface extends ContentEntityStorageInterface {

  /**
   * Loads the top-level transcript hosted by an entity.
   *
   * Returns the non-error turns with no parent, ordered by created then id.
   * Sub-agent children (rows with a parent) and error rows are excluded.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface[]
   *   The top-level message entities, keyed by id, or an empty array.
   */
  public function loadTranscript(EntityInterface $host): array;

  /**
   * Loads the whole conversation hosted by an entity as a nested tree.
   *
   * Unlike the transcript, this includes every row (error rows and sub-agent
   * children) and reconstructs the parent/child nesting from the parent
   * back-reference. Intended for a full debug view or export.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   *
   * @return array
   *   A list of root nodes ordered by created then id, each an array with:
   *   - message: \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   - children: array of child nodes in the same shape, recursively.
   */
  public function loadTree(EntityInterface $host): array;

  /**
   * Deletes every message hosted by an entity.
   *
   * Removes the whole conversation, sub-agent children included.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   */
  public function deleteForHost(EntityInterface $host): void;

}
