<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Store;

/**
 * Factory for creating scoped conversation store instances.
 *
 * Each store is scoped to a collection (plugin namespace) and a
 * thread ID, isolated per user session via PrivateTempStore.
 */
interface ConversationStoreFactoryInterface {

  /**
   * Returns a conversation store scoped to a collection and thread.
   *
   * @param string $collection
   *   The store collection name (e.g. 'oe_ai_drafting').
   * @param string $threadId
   *   The conversation thread identifier.
   *
   * @return \Drupal\oe_ai_assistant\Store\ConversationStoreInterface
   *   A scoped store instance.
   */
  public function getStore(string $collection, string $threadId): ConversationStoreInterface;

}
