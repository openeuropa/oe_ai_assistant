<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * TempStore-based conversation history management.
 *
 * Provides load, save, and delete operations for conversation
 * message arrays, scoped by collection name and thread ID.
 *
 * Load returns the full stored history. Save trims to a
 * configurable number of message pairs as a storage-size safety
 * net. Context-window management is handled externally by
 * AiShortTermMemory plugins.
 *
 * Storage is user-scoped via PrivateTempStore, so conversation
 * threads are not shared across Drupal user sessions.
 */
class ConversationHistory {

  /**
   * Default number of message pairs to retain.
   *
   * A pair is one user message plus one assistant message. This value
   * (10 pairs = 20 messages) balances context quality against the size
   * of the conversation history sent to the LLM on each request.
   */
  private const DEFAULT_HISTORY_LENGTH = 10;

  /**
   * Constructs a new ConversationHistory.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store factory used to obtain named store instances.
   *   PrivateTempStore scopes data to the current user session, so each
   *   Drupal user has their own isolated conversation history.
   */
  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * Loads conversation history for a thread.
   *
   * Returns the full stored message array without trimming.
   * Context-window management is handled by AiShortTermMemory
   * plugins; this method only retrieves persisted data.
   *
   * @param string $collection
   *   The TempStore collection name (e.g. 'oe_ai_drafting').
   * @param string $threadId
   *   The thread identifier.
   *
   * @return array
   *   Array of message arrays, each with at least 'role' and
   *   'content' keys. Empty array for new threads.
   */
  public function load(
    string $collection,
    string $threadId,
  ): array {
    $store = $this->tempStoreFactory->get($collection);
    return $store->get($threadId) ?? [];
  }

  /**
   * Saves conversation history, trimming for storage safety.
   *
   * Trims to the last N message pairs before persisting so the
   * TempStore value stays bounded. This is a storage-size safety
   * net, not a context-window strategy -- AiShortTermMemory
   * plugins handle context management.
   *
   * @param string $collection
   *   The TempStore collection name.
   * @param string $threadId
   *   The thread identifier.
   * @param array $history
   *   The full message array to save.
   * @param int $maxPairs
   *   Maximum message pairs to retain in storage.
   */
  public function save(
    string $collection,
    string $threadId,
    array $history,
    int $maxPairs = self::DEFAULT_HISTORY_LENGTH,
  ): void {
    $store = $this->tempStoreFactory->get($collection);
    // Trim before storing so the TempStore value stays bounded.
    $trimmed = array_slice($history, -($maxPairs * 2));
    $store->set($threadId, $trimmed);
  }

  /**
   * Deletes a conversation thread from the store.
   *
   * After deletion, a subsequent load() for the same collection and
   * thread ID will return an empty array as if the thread never existed.
   *
   * @param string $collection
   *   The TempStore collection name.
   * @param string $threadId
   *   The thread identifier to remove.
   */
  public function delete(
    string $collection,
    string $threadId,
  ): void {
    $store = $this->tempStoreFactory->get($collection);
    $store->delete($threadId);
  }

}
