<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Subagent;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Update;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentResult;

/**
 * Keeps a record of every task a subagent runs for a session.
 */
final class SubagentTaskLedger {

  /**
   * The table of the ledger.
   */
  public const string TABLE = 'oe_ai_assistant_subagent_task';

  /**
   * The task is running.
   */
  public const string RUNNING = 'running';

  /**
   * The task stopped to ask the editor something.
   */
  public const string NEEDS_INPUT = 'needs_input';

  /**
   * The task is queued and no worker holds it.
   */
  public const string PENDING = 'pending';

  /**
   * The task runs in the background.
   */
  public const string BACKGROUND = 'background';

  /**
   * The task runs while the turn that asked for it waits.
   */
  public const string FOREGROUND = 'foreground';

  /**
   * The most attempts a background task gets.
   */
  public const int MAX_ATTEMPTS = 3;

  /**
   * How long a pending task waits before it counts as lost, in seconds.
   */
  public const int STALE_AFTER = 30;

  /**
   * The result of the task waits to be delivered to the conversation.
   */
  public const string WAITING = 'waiting';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records a task as running, or as pending when it runs in the background.
   *
   * @param string $taskId
   *   The id of the task.
   * @param int $sessionId
   *   The id of the session.
   * @param string $tool
   *   The tool whose call the task answers.
   * @param string $subagent
   *   The name of the subagent.
   * @param string $callId
   *   The id of the call.
   * @param string $parentThread
   *   The thread of the agent that made the call.
   * @param int|null $uid
   *   The user the task runs for.
   * @param array $input
   *   The inputs of the call.
   * @param bool $background
   *   Whether the task runs in the background.
   */
  public function record(string $taskId, int $sessionId, string $tool, string $subagent, string $callId, string $parentThread, ?int $uid, array $input, bool $background = FALSE): void {
    $now = $this->time->getRequestTime();

    // A replay of the same call finds the row again and only touches it.
    // insertFields() replaces the fields set before it, so the key comes after.
    $this->database->merge(self::TABLE)
      ->insertFields([
        'session_id' => $sessionId,
        'tool' => $tool,
        'subagent' => $subagent,
        'call_id' => $callId,
        'parent_thread' => $parentThread,
        'uid' => $uid,
        'input' => json_encode($input, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR),
        'mode' => $background ? self::BACKGROUND : self::FOREGROUND,
        'status' => $background ? self::PENDING : self::RUNNING,
        'created' => $now,
        'changed' => $now,
      ])
      ->updateFields(['changed' => $now])
      ->key('task_id', $taskId)
      ->execute();
  }

  /**
   * Takes a task that waits for the editor back to running or pending.
   *
   * @param string $taskId
   *   The id of the task.
   * @param int $sessionId
   *   The id of the session the call belongs to.
   * @param string $tool
   *   The tool the call is made through.
   * @param array $input
   *   The inputs of the call that continues the task.
   * @param bool $background
   *   Whether the task now runs in the background.
   *
   * @return int|string
   *   The number of continuations the task has now, or the reason for the
   *   model when the task cannot be continued.
   */
  public function continueTask(string $taskId, int $sessionId, string $tool, array $input, bool $background = FALSE): int|string {
    $updated = $this->database->update(self::TABLE)
      ->expression('continuations', 'continuations + 1')
      ->fields([
        'input' => json_encode($input, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR),
        'mode' => $background ? self::BACKGROUND : self::FOREGROUND,
        'status' => $background ? self::PENDING : self::RUNNING,
        // A continuation starts over: no earlier claim or dispatch applies.
        'dispatched' => NULL,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('task_id', $taskId)
      ->condition('session_id', $sessionId)
      ->condition('tool', $tool)
      ->condition('status', self::NEEDS_INPUT)
      ->execute();

    if ($updated > 0) {
      return (int) $this->database->select(self::TABLE, 't')
        ->fields('t', ['continuations'])
        ->condition('task_id', $taskId)
        ->execute()
        ->fetchField();
    }

    $row = $this->load($taskId);
    // Another session's or another tool's task is reported as unknown, so a
    // task id says nothing about work that is not the caller's.
    if ($row === NULL || (int) $row['session_id'] !== $sessionId || $row['tool'] !== $tool) {
      return sprintf('Unknown task_id "%s".', $taskId);
    }

    return sprintf('Task "%s" is %s and cannot be continued.', $taskId, $row['status']);
  }

  /**
   * Counts the background tasks of a session that have not finished.
   *
   * @param int $sessionId
   *   The id of the session.
   *
   * @return int
   *   The number of background tasks that are pending or running.
   */
  public function activeBackground(int $sessionId): int {
    return (int) $this->database->select(self::TABLE, 't')
      ->condition('session_id', $sessionId)
      ->condition('mode', self::BACKGROUND)
      ->condition('status', [self::PENDING, self::RUNNING], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Lists the pending background tasks of a session that were not queued.
   *
   * @param int $sessionId
   *   The id of the session.
   *
   * @return string[]
   *   The task ids.
   */
  public function undispatched(int $sessionId): array {
    return $this->database->select(self::TABLE, 't')
      ->fields('t', ['task_id'])
      ->condition('session_id', $sessionId)
      ->condition('mode', self::BACKGROUND)
      ->condition('status', self::PENDING)
      ->isNull('dispatched')
      ->orderBy('created')
      ->orderBy('task_id')
      ->execute()
      ->fetchCol();
  }

  /**
   * Records that a task was put on the queue.
   *
   * @param string $taskId
   *   The id of the task.
   */
  public function markDispatched(string $taskId): void {
    $this->database->update(self::TABLE)
      ->fields(['dispatched' => $this->time->getCurrentTime()])
      ->condition('task_id', $taskId)
      ->execute();
  }

  /**
   * Claims a background task for a worker.
   *
   * @param string $taskId
   *   The id of the task.
   * @param string $worker
   *   The id of the worker.
   * @param int $leaseSeconds
   *   How long the claim holds without being renewed.
   *
   * @return array|null
   *   The row once claimed, or NULL when another worker holds the task, it
   *   is not claimable, or it has used up its attempts.
   */
  public function claim(string $taskId, string $worker, int $leaseSeconds): ?array {
    $now = $this->time->getCurrentTime();

    $claim = $this->database->update(self::TABLE)
      ->expression('attempts', 'attempts + 1')
      ->fields([
        'status' => self::RUNNING,
        'claimed_by' => $worker,
        'lease_expires' => $now + $leaseSeconds,
        'changed' => $now,
      ])
      ->condition('attempts', self::MAX_ATTEMPTS, '<');
    $this->claimable($claim, $taskId, $now);
    if ($claim->execute() === 1) {
      return $this->load($taskId);
    }

    // A task that is claimable but has no attempt left fails here, so a task
    // that keeps killing its worker is not run again.
    $exhausted = $this->database->update(self::TABLE)
      ->fields([
        'status' => SubagentResult::FAILED,
        'result' => json_encode(['error' => sprintf('The task failed after %d attempts.', self::MAX_ATTEMPTS)], JSON_THROW_ON_ERROR),
        'delivery_state' => self::WAITING,
        'claimed_by' => NULL,
        'lease_expires' => NULL,
        'changed' => $now,
      ])
      ->condition('attempts', self::MAX_ATTEMPTS, '>=');
    $this->claimable($exhausted, $taskId, $now);
    $exhausted->execute();

    return NULL;
  }

  /**
   * Extends the claim a worker holds on a task.
   *
   * @param string $taskId
   *   The id of the task.
   * @param string $worker
   *   The id of the worker.
   * @param int $leaseSeconds
   *   How long the claim holds from now.
   *
   * @return bool
   *   FALSE when the worker no longer holds the task.
   */
  public function renew(string $taskId, string $worker, int $leaseSeconds): bool {
    $now = $this->time->getCurrentTime();

    return $this->database->update(self::TABLE)
      ->fields(['lease_expires' => $now + $leaseSeconds, 'changed' => $now])
      ->condition('task_id', $taskId)
      ->condition('claimed_by', $worker)
      ->condition('status', self::RUNNING)
      ->execute() === 1;
  }

  /**
   * Settles a task the worker holds, and marks its result as waiting.
   *
   * @param string $taskId
   *   The id of the task.
   * @param string $worker
   *   The id of the worker.
   * @param string $status
   *   The status the task ends in.
   * @param array $result
   *   The result of the task.
   *
   * @return bool
   *   FALSE when the worker no longer holds the task, and nothing changed.
   */
  public function finishClaimed(string $taskId, string $worker, string $status, array $result): bool {
    return $this->database->update(self::TABLE)
      ->fields([
        'status' => $status,
        'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR),
        'delivery_state' => self::WAITING,
        'claimed_by' => NULL,
        'lease_expires' => NULL,
        'changed' => $this->time->getCurrentTime(),
      ])
      ->condition('task_id', $taskId)
      ->condition('claimed_by', $worker)
      ->execute() === 1;
  }

  /**
   * Lists the background tasks that no queue item is going to run.
   *
   * @param int $now
   *   The time to judge by.
   *
   * @return string[]
   *   The task ids: pending tasks that were never queued, or whose queue
   *   item was lost, and running tasks whose lease lapsed.
   */
  public function stale(int $now): array {
    $query = $this->database->select(self::TABLE, 't')
      ->fields('t', ['task_id'])
      ->condition('mode', self::BACKGROUND)
      ->orderBy('created')
      ->orderBy('task_id');

    // A task of the turn in flight is not queued before the turn ends, so a
    // pending task is only stale once it has waited a while.
    $pending = $query->andConditionGroup()
      ->condition('status', self::PENDING)
      ->condition('changed', $now - self::STALE_AFTER, '<')
      ->condition($query->orConditionGroup()
        ->isNull('dispatched')
        ->condition('dispatched', $now - self::STALE_AFTER, '<'));
    $running = $query->andConditionGroup()
      ->condition('status', self::RUNNING)
      ->condition('lease_expires', $now, '<');
    $query->condition($query->orConditionGroup()->condition($pending)->condition($running));

    return $query->execute()->fetchCol();
  }

  /**
   * Restricts a statement to a background task a worker may claim.
   */
  private function claimable(Update $query, string $taskId, int $now): void {
    $query->condition('task_id', $taskId)
      ->condition('mode', self::BACKGROUND)
      ->condition('stop_requested', 0)
      ->condition($query->orConditionGroup()
        ->condition('status', self::PENDING)
        ->condition($query->andConditionGroup()
          ->condition('status', self::RUNNING)
          ->condition('lease_expires', $now, '<')));
  }

  /**
   * Settles a task.
   *
   * @param string $taskId
   *   The id of the task.
   * @param string $status
   *   The status the task ends in.
   * @param array $result
   *   The result of the task.
   */
  public function finish(string $taskId, string $status, array $result): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => $status,
        'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR),
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('task_id', $taskId)
      ->execute();
  }

  /**
   * Loads a task.
   *
   * @param string $taskId
   *   The id of the task.
   *
   * @return array|null
   *   The row, with its input and result decoded, or NULL when none exists.
   */
  public function load(string $taskId): ?array {
    $row = $this->database->select(self::TABLE, 't')
      ->fields('t')
      ->condition('task_id', $taskId)
      ->execute()
      ->fetchAssoc();

    return $row ? $this->decode($row) : NULL;
  }

  /**
   * Returns the tasks of a session, oldest first.
   *
   * @param int $sessionId
   *   The id of the session.
   *
   * @return array[]
   *   The rows, keyed by task id.
   */
  public function forSession(int $sessionId): array {
    $rows = $this->database->select(self::TABLE, 't')
      ->fields('t')
      ->condition('session_id', $sessionId)
      ->orderBy('created')
      ->orderBy('task_id')
      ->execute()
      ->fetchAllAssoc('task_id', \PDO::FETCH_ASSOC);

    return array_map($this->decode(...), $rows);
  }

  /**
   * Deletes the tasks of a session.
   *
   * @param int $sessionId
   *   The id of the session.
   */
  public function deleteForSession(int $sessionId): void {
    $this->database->delete(self::TABLE)
      ->condition('session_id', $sessionId)
      ->execute();
  }

  /**
   * Decodes the JSON columns of a row.
   */
  private function decode(array $row): array {
    $row['input'] = json_decode((string) $row['input'], TRUE) ?? [];
    $row['result'] = $row['result'] === NULL ? NULL : json_decode((string) $row['result'], TRUE);

    return $row;
  }

}
