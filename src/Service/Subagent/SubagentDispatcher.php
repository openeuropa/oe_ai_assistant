<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Subagent;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Puts the background tasks of subagents on the queue their worker reads.
 */
final class SubagentDispatcher {

  /**
   * The queue, and the id of the worker plugin that reads it.
   */
  public const string QUEUE = 'oe_ai_assistant_subagent_task';

  public function __construct(
    private readonly SubagentTaskLedger $ledger,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
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
   * @return int
   *   The number of tasks queued.
   */
  public function sweep(): int {
    return $this->dispatch($this->ledger->stale($this->time->getCurrentTime()));
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
