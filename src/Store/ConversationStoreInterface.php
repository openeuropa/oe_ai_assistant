<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Store;

/**
 * Interface for a scoped conversation history store.
 *
 * Each instance is bound to a specific collection and thread ID.
 * Use ConversationStoreFactoryInterface::getStore() to obtain a
 * scoped instance.
 *
 * System prompts are NOT stored -- they are rebuilt fresh on each
 * request by the plugin. Only user, assistant, and tool messages
 * are persisted.
 */
interface ConversationStoreInterface {

  /**
   * Loads the conversation history.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage[]
   *   The stored messages, or empty array for new conversations.
   */
  public function load(): array;

  /**
   * Saves conversation history.
   *
   * Trims to the configured maximum to bound storage size.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $messages
   *   The messages to persist.
   */
  public function save(array $messages): void;

  /**
   * Deletes the stored conversation.
   */
  public function drop(): void;

}
