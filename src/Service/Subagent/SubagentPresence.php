<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Subagent;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;

/**
 * Remembers which sessions have a browser that polls for background results.
 */
final class SubagentPresence {

  /**
   * The collection that holds the marks.
   */
  private const string COLLECTION = 'oe_ai_assistant.subagent_presence';

  /**
   * How long a mark lasts without being renewed, in seconds.
   */
  private const int TTL = 15;

  /**
   * The store of the marks.
   */
  private readonly KeyValueStoreExpirableInterface $store;

  public function __construct(KeyValueExpirableFactoryInterface $factory) {
    $this->store = $factory->get(self::COLLECTION);
  }

  /**
   * Records that a browser is present for the session.
   *
   * @param int $sessionId
   *   The id of the session.
   */
  public function touch(int $sessionId): void {
    $this->store->setWithExpire((string) $sessionId, 1, self::TTL);
  }

  /**
   * Tells whether a browser polled for the session lately.
   *
   * @param int $sessionId
   *   The id of the session.
   */
  public function isPresent(int $sessionId): bool {
    return $this->store->has((string) $sessionId);
  }

}
