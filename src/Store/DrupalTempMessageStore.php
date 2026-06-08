<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Store;

use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Persists Symfony AI MessageBag via Drupal's PrivateTempStore.
 *
 * Each instance is scoped to a collection (plugin namespace) and a
 * thread ID. The store is user-scoped via PrivateTempStore, so
 * conversations are isolated per Drupal user session.
 *
 * The system message is NOT stored -- it is rebuilt fresh on each
 * request by the chat plugin. Only user, assistant, and tool call
 * messages are persisted.
 */
class DrupalTempMessageStore {

  /**
   * The underlying Drupal temp store instance.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  private readonly PrivateTempStore $store;

  /**
   * Constructs a DrupalTempMessageStore.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $factory
   *   The private temp store factory.
   * @param string $collection
   *   The TempStore collection name (e.g. 'oe_ai_drafting').
   * @param string $threadId
   *   The conversation thread identifier.
   * @param int $maxMessages
   *   Maximum messages to retain on save (storage safety net).
   */
  public function __construct(
    PrivateTempStoreFactory $factory,
    string $collection,
    private readonly string $threadId,
    private readonly int $maxMessages = 20,
  ) {
    $this->store = $factory->get($collection);
    if ($this->maxMessages < 1) {
      throw new \InvalidArgumentException(
        'maxMessages must be at least 1.',
      );
    }
  }

  /**
   * Loads the conversation history for this thread.
   *
   * Returns an empty MessageBag for new or cleared threads.
   *
   * @return \Symfony\AI\Platform\Message\MessageBag
   *   The stored conversation messages.
   */
  public function load(): MessageBag {
    $data = $this->store->get($this->threadId);
    if ($data instanceof MessageBag) {
      return $data;
    }
    return new MessageBag();
  }

  /**
   * Saves the conversation history, trimming for storage safety.
   *
   * Keeps the last $maxMessages messages to bound storage size.
   * This also serves as the context-window safety net.
   *
   * @param \Symfony\AI\Platform\Message\MessageBag $messages
   *   The full conversation to persist.
   */
  public function save(MessageBag $messages): void {
    $allMessages = $messages->getMessages();
    if (count($allMessages) > $this->maxMessages) {
      $trimmed = array_slice($allMessages, -$this->maxMessages);
      $messages = new MessageBag(...$trimmed);
    }
    $this->store->set($this->threadId, $messages);
  }

  /**
   * Deletes the stored conversation for this thread.
   */
  public function drop(): void {
    $this->store->delete($this->threadId);
  }

}
