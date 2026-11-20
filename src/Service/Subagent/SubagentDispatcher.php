<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Subagent;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Puts the background tasks of subagents on the queue their worker reads.
 */
final class SubagentDispatcher {

  /**
   * The queue, and the id of the worker plugin that reads it.
   */
  public const string QUEUE = 'oe_ai_assistant_subagent_task';

  /**
   * The queue of the deliveries, and the id of the worker plugin that reads it.
   */
  public const string DELIVERY_QUEUE = 'oe_ai_assistant_subagent_delivery';

  /**
   * How long a result waits before the sweep queues its delivery, in seconds.
   */
  private const int DELIVERY_SWEEP_AFTER = 30;

  /**
   * The key-value collection that marks the sessions with a queued delivery.
   */
  public const string DELIVERY_QUEUED_COLLECTION = 'oe_ai_assistant.subagent_delivery_queued';

  /**
   * How long a queued mark lasts, in seconds; it covers a lost queue item.
   */
  public const int DELIVERY_QUEUED_TTL = 120;

  public function __construct(
    private readonly SubagentTaskLedger $ledger,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
  ) {}

  /**
   * Queues the background tasks of a session that were not queued yet.
   *
   * @param int $sessionId
   *   The id of the session.
   *
   * @return int
   *   The number of tasks queued.
   */
  public function dispatchSession(int $sessionId): int {
    return $this->dispatch($this->ledger->undispatched($sessionId));
  }

  /**
   * Queues the background tasks that no queue item is going to run.
   *
   * It also queues the delivery of every session whose results wait.
   *
   * @return int
   *   The number of tasks queued.
   */
  public function sweep(): int {
    $now = $this->time->getCurrentTime();
    foreach ($this->ledger->sessionsWithWaiting($now - self::DELIVERY_SWEEP_AFTER) as $sessionId) {
      $this->enqueueDelivery($sessionId);
    }

    return $this->dispatch($this->ledger->stale($now));
  }

  /**
   * Queues the delivery of the results that wait in a session.
   *
   * A session with a delivery already queued gets no second item.
   *
   * @param int $sessionId
   *   The id of the session.
   */
  public function enqueueDelivery(int $sessionId): void {
    $queued = $this->keyValueExpirable->get(self::DELIVERY_QUEUED_COLLECTION);
    if ($queued->setWithExpireIfNotExists((string) $sessionId, TRUE, self::DELIVERY_QUEUED_TTL)) {
      $this->queueFactory->get(self::DELIVERY_QUEUE)->createItem(['session_id' => $sessionId]);
    }
  }

  /**
   * Queues tasks and marks them dispatched.
   *
   * @param string[] $taskIds
   *   The ids of the tasks.
   *
   * @return int
   *   The number of tasks queued.
   */
  private function dispatch(array $taskIds): int {
    $queue = $this->queueFactory->get(self::QUEUE);
    foreach ($taskIds as $taskId) {
      $queue->createItem(['task_id' => $taskId]);
      $this->ledger->markDispatched($taskId);
    }

    return count($taskIds);
  }

}
