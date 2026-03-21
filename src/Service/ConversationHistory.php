<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * TempStore-based conversation history management.
 *
 * Provides load, save, and delete operations for conversation
 * message arrays, scoped by collection name and thread ID.
 * Automatically trims history to a configurable number of
 * message pairs on both load and save.
 */
class ConversationHistory {

  /**
   * Default number of message pairs to retain.
   */
  private const DEFAULT_HISTORY_LENGTH = 10;

  /**
   * Constructs a new ConversationHistory.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store factory.
   */
  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * Loads conversation history, trimmed to the last N pairs.
   *
   * @param string $collection
   *   The TempStore collection name (e.g. 'oe_ai_drafting').
   * @param string $threadId
   *   The thread identifier.
   * @param int $maxPairs
   *   Maximum message pairs to return. Each pair is a
   *   user + assistant message, so the actual message count
   *   limit is $maxPairs * 2.
   *
   * @return array
   *   Array of message arrays with role/content keys.
   */
  public function load(
    string $collection,
    string $threadId,
    int $maxPairs = self::DEFAULT_HISTORY_LENGTH,
  ): array {
    $store = $this->tempStoreFactory->get($collection);
    $history = $store->get($threadId) ?? [];
    return array_slice($history, -($maxPairs * 2));
  }

  /**
   * Saves conversation history, trimming to the last N pairs.
   *
   * @param string $collection
   *   The TempStore collection name.
   * @param string $threadId
   *   The thread identifier.
   * @param array $history
   *   The full message array to save.
   * @param int $maxPairs
   *   Maximum message pairs to retain.
   */
  public function save(
    string $collection,
    string $threadId,
    array $history,
    int $maxPairs = self::DEFAULT_HISTORY_LENGTH,
  ): void {
    $store = $this->tempStoreFactory->get($collection);
    $trimmed = array_slice($history, -($maxPairs * 2));
    $store->set($threadId, $trimmed);
  }

  /**
   * Deletes a conversation thread.
   *
   * @param string $collection
   *   The TempStore collection name.
   * @param string $threadId
   *   The thread identifier.
   */
  public function delete(
    string $collection,
    string $threadId,
  ): void {
    $store = $this->tempStoreFactory->get($collection);
    $store->delete($threadId);
  }

}
