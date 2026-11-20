<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Subagent;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

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

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records a task as running.
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
   */
  public function record(string $taskId, int $sessionId, string $tool, string $subagent, string $callId, string $parentThread, ?int $uid, array $input): void {
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
        'status' => self::RUNNING,
        'created' => $now,
        'changed' => $now,
      ])
      ->updateFields(['changed' => $now])
      ->key('task_id', $taskId)
      ->execute();
  }

  /**
   * Takes a task that waits for the editor back to running.
   *
   * @param string $taskId
   *   The id of the task.
   * @param int $sessionId
   *   The id of the session the call belongs to.
   * @param string $tool
   *   The tool the call is made through.
   *
   * @return int|string
   *   The number of continuations the task has now, or the reason for the
   *   model when the task cannot be continued.
   */
  public function continueTask(string $taskId, int $sessionId, string $tool): int|string {
    $updated = $this->database->update(self::TABLE)
      ->expression('continuations', 'continuations + 1')
      ->fields(['status' => self::RUNNING, 'changed' => $this->time->getRequestTime()])
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
