<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Store;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\TempStore\PrivateTempStore;

/**
 * Scoped conversation history store backed by PrivateTempStore.
 *
 * Messages are stored as simple arrays for serialization and
 * reconstructed as ChatMessage objects on load. Bound to a
 * specific collection and thread ID at construction time.
 */
class ConversationStore implements ConversationStoreInterface {

  /**
   * Maximum messages to retain per thread.
   */
  protected const MAX_MESSAGES = 20;

  /**
   * Constructs a ConversationStore.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStore $store
   *   The scoped temp store instance.
   * @param string $threadId
   *   The conversation thread identifier.
   */
  public function __construct(
    protected readonly PrivateTempStore $store,
    protected readonly string $threadId,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    $data = $this->store->get($this->threadId);
    if (!is_array($data)) {
      return [];
    }
    return array_map(
      fn(array $m) => new ChatMessage($m['role'], $m['text']),
      $data,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $messages): void {
    if (count($messages) > static::MAX_MESSAGES) {
      $messages = array_slice($messages, -static::MAX_MESSAGES);
    }
    $data = array_map(
      fn(ChatMessage $m) => ['role' => $m->getRole(), 'text' => $m->getText()],
      $messages,
    );
    $this->store->set($this->threadId, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function drop(): void {
    $this->store->delete($this->threadId);
  }

}
