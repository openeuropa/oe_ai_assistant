<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Subagent;

use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentResult;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask;
use Drupal\oe_ai_assistant\Neuron\Tools\SubagentToolInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a queued background task of a subagent.
 */
final class SubagentRunner {

  /**
   * How long a claim holds without being renewed, in seconds.
   */
  private const int LEASE = 300;

  /**
   * How often the claim is renewed while the subagent runs, in seconds.
   */
  private const int RENEW_EVERY = 30;

  public function __construct(
    private readonly SubagentTaskLedger $ledger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly NeuronToolManagerInterface $toolManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Claims a task and runs it, with the events of the subagent discarded.
   *
   * @param string $taskId
   *   The id of the task.
   */
  public function run(string $taskId): void {
    $worker = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
    $row = $this->ledger->claim($taskId, $worker, self::LEASE);
    if ($row === NULL) {
      return;
    }

    $uid = $row['uid'] === NULL ? NULL : (int) $row['uid'];
    $this->accountSwitcher->switchTo($uid === NULL
      ? new AnonymousUserSession()
      : ($this->entityTypeManager->getStorage('user')->load($uid) ?? new AnonymousUserSession()));

    try {
      $session = $this->entityTypeManager->getStorage('ai_editorial_session')->load($row['session_id']);
      if ($session === NULL) {
        $this->ledger->finishClaimed($taskId, $worker, SubagentResult::FAILED, ['error' => 'The session of the task no longer exists.']);

        return;
      }

      $tool = $this->toolManager->createTool($row['tool'], ['session' => $session]);
      assert($tool instanceof SubagentToolInterface);
      $definition = $tool->definition();
      $input = $row['input'];
      $prompt = $input[$definition->promptProperty] ?? '';

      $task = new SubagentTask(
        $taskId,
        $row['call_id'],
        is_string($prompt) ? $prompt : '',
        $input,
        continuation: (int) $row['continuations'] > 0,
        turn: (int) $row['continuations'],
        background: TRUE,
      );

      $lastRenewal = $this->time->getCurrentTime();
      $reports = $definition->subagent->run($task);
      foreach ($reports as $report) {
        unset($report);
        if ($this->time->getCurrentTime() - $lastRenewal < self::RENEW_EVERY) {
          continue;
        }
        // A renewal that fails means the lease lapsed and another worker
        // holds the task now, so this one stops without writing anything.
        if (!$this->ledger->renew($taskId, $worker, self::LEASE)) {
          return;
        }
        $lastRenewal = $this->time->getCurrentTime();
      }
      $result = $reports->getReturn();
      assert($result instanceof SubagentResult);

      $this->ledger->finishClaimed($taskId, $worker, $result->status, $result->data);
    }
    catch (\Throwable $e) {
      $this->logger->error('Background subagent task @task failed: @message', [
        '@task' => $taskId,
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);
      $this->ledger->finishClaimed($taskId, $worker, SubagentResult::FAILED, ['error' => 'The subagent failed.']);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

}
