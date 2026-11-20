<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\Update;

/**
 * Keeps the jobs that draft the field groups of a draft in parallel.
 */
final class DraftGroupJobs {

  /**
   * The table of the jobs.
   */
  public const string TABLE = 'oe_ai_assistant_draft_group_job';

  /**
   * The job waits for a worker.
   */
  public const string PENDING = 'pending';

  /**
   * A worker holds the job.
   */
  public const string RUNNING = 'running';

  /**
   * The group was drafted.
   */
  public const string DONE = 'done';

  /**
   * The group could not be drafted.
   */
  public const string FAILED = 'failed';

  /**
   * The draft no longer needs the job.
   */
  public const string ABANDONED = 'abandoned';

  /**
   * The most attempts a job gets.
   */
  public const int MAX_ATTEMPTS = 3;

  /**
   * How long a claim holds without being renewed, in seconds.
   */
  public const int LEASE = 300;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Builds the id of the job of a group of a draft.
   */
  public static function jobId(string $draftTask, string $callId, string $groupId): string {
    return 'grp_' . substr(hash('sha256', implode("\0", [$draftTask, $callId, $groupId])), 0, 24);
  }

  /**
   * Records a job, unless the job exists already.
   *
   * @return bool
   *   TRUE when the job is new, FALSE when a replay found it.
   */
  public function record(string $jobId, int $sessionId, ?int $uid, string $draftTask, string $callId, string $groupId, string $run, array $input): bool {
    $now = $this->time->getCurrentTime();

    // The key makes the insert atomic, so a replay never overwrites the row.
    $result = $this->database->merge(self::TABLE)
      ->insertFields([
        'session_id' => $sessionId,
        'uid' => $uid,
        'draft_task' => $draftTask,
        'call_id' => $callId,
        'group_id' => $groupId,
        'run' => $run,
        'input' => json_encode($input, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR),
        'status' => self::PENDING,
        'created' => $now,
        'changed' => $now,
      ])
      ->key('job_id', $jobId)
      ->execute();

    return $result === Merge::STATUS_INSERT;
  }

  /**
   * Claims a job for a worker.
   *
   * @return array|null
   *   The row once claimed, or NULL when another worker holds the job, it is
   *   not claimable, or it has used up its attempts.
   */
  public function claim(string $jobId, string $worker, int $leaseSeconds = self::LEASE): ?array {
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
    $this->claimable($claim, $jobId, $now);
    if ($claim->execute() === 1) {
      return $this->load($jobId);
    }

    // A job that keeps killing its worker is not run again.
    $exhausted = $this->database->update(self::TABLE)
      ->fields([
        'status' => self::FAILED,
        'error' => 'The group could not be drafted.',
        'claimed_by' => NULL,
        'lease_expires' => NULL,
        'changed' => $now,
      ])
      ->condition('attempts', self::MAX_ATTEMPTS, '>=');
    $this->claimable($exhausted, $jobId, $now);
    $exhausted->execute();

    return NULL;
  }

  /**
   * Extends the claim a worker holds on a job.
   *
   * @return bool
   *   FALSE when the worker no longer holds the job.
   */
  public function renew(string $jobId, string $worker, int $leaseSeconds = self::LEASE): bool {
    $now = $this->time->getCurrentTime();

    return $this->database->update(self::TABLE)
      ->fields(['lease_expires' => $now + $leaseSeconds, 'changed' => $now])
      ->condition('job_id', $jobId)
      ->condition('claimed_by', $worker)
      ->condition('status', self::RUNNING)
      ->execute() === 1;
  }

  /**
   * Writes how far the drafter has written, while the worker holds the job.
   */
  public function progress(string $jobId, string $worker, array $progress): void {
    $this->database->update(self::TABLE)
      ->fields([
        'progress' => json_encode($progress, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR),
        'changed' => $this->time->getCurrentTime(),
      ])
      ->condition('job_id', $jobId)
      ->condition('claimed_by', $worker)
      ->condition('status', self::RUNNING)
      ->execute();
  }

  /**
   * Settles a job the worker holds.
   *
   * @return bool
   *   FALSE when the worker no longer holds the job, and nothing changed.
   */
  public function finish(string $jobId, string $worker, string $status, ?array $result, string $error = ''): bool {
    return $this->database->update(self::TABLE)
      ->fields([
        'status' => $status,
        'result' => $result === NULL ? NULL : json_encode($result, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR),
        'error' => $error === '' ? NULL : $error,
        'claimed_by' => NULL,
        'lease_expires' => NULL,
        'changed' => $this->time->getCurrentTime(),
      ])
      ->condition('job_id', $jobId)
      ->condition('claimed_by', $worker)
      ->condition('status', self::RUNNING)
      ->execute() === 1;
  }

  /**
   * Loads a job.
   *
   * @return array|null
   *   The row, decoded, or NULL when none exists.
   */
  public function load(string $jobId): ?array {
    $row = $this->database->select(self::TABLE, 'j')
      ->fields('j')
      ->condition('job_id', $jobId)
      ->execute()
      ->fetchAssoc();

    return $row ? $this->decode($row) : NULL;
  }

  /**
   * Returns the jobs of a draft.
   *
   * @return array[]
   *   The rows, decoded and keyed by group id.
   */
  public function forDraft(string $draftTask, string $callId): array {
    $rows = $this->database->select(self::TABLE, 'j')
      ->fields('j')
      ->condition('draft_task', $draftTask)
      ->condition('call_id', $callId)
      ->orderBy('created')
      ->orderBy('job_id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $jobs = [];
    foreach ($rows as $row) {
      $jobs[$row['group_id']] = $this->decode($row);
    }

    return $jobs;
  }

  /**
   * Abandons the jobs of a draft that no worker has claimed.
   */
  public function abandonDraft(string $draftTask, string $callId): void {
    $this->database->update(self::TABLE)
      ->fields(['status' => self::ABANDONED, 'changed' => $this->time->getCurrentTime()])
      ->condition('draft_task', $draftTask)
      ->condition('call_id', $callId)
      ->condition('status', self::PENDING)
      ->execute();
  }

  /**
   * Deletes the jobs of a session.
   */
  public function deleteForSession(int $sessionId): void {
    $this->database->delete(self::TABLE)
      ->condition('session_id', $sessionId)
      ->execute();
  }

  /**
   * Deletes the settled jobs that last changed before a time.
   *
   * @return int
   *   The number of jobs deleted.
   */
  public function deleteFinishedBefore(int $time): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('status', [self::DONE, self::FAILED, self::ABANDONED], 'IN')
      ->condition('changed', $time, '<')
      ->execute();
  }

  /**
   * Restricts a statement to a job a worker may claim.
   */
  private function claimable(Update $query, string $jobId, int $now): void {
    $query->condition('job_id', $jobId)
      ->condition($query->orConditionGroup()
        ->condition('status', self::PENDING)
        ->condition($query->andConditionGroup()
          ->condition('status', self::RUNNING)
          ->condition('lease_expires', $now, '<')));
  }

  /**
   * Decodes the JSON columns of a row and casts its integers.
   */
  private function decode(array $row): array {
    $row['input'] = json_decode((string) $row['input'], TRUE) ?? [];
    foreach (['progress', 'result'] as $column) {
      $value = $row[$column] === NULL ? NULL : json_decode((string) $row[$column], TRUE);
      $row[$column] = $value === [] || $value === '' ? NULL : $value;
    }
    foreach (['session_id', 'attempts', 'created', 'changed'] as $column) {
      $row[$column] = (int) $row[$column];
    }
    foreach (['uid', 'lease_expires'] as $column) {
      $row[$column] = $row[$column] === NULL ? NULL : (int) $row[$column];
    }

    return $row;
  }

}
