<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Subagent;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;

/**
 * Tells whether the editorial run of a session can take a new turn.
 */
final class EditorialTurnGuard {

  public function __construct(
    private readonly PersistenceInterface $persistence,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Tells whether a turn of the session is still running or suspended.
   *
   * The engine keeps one run per id, and every editorial turn runs under the
   * thread id of the session. A run holds a lease while it works, and the
   * engine replaces a run whose lease has run out, so that one counts as gone.
   * A suspended run waits for an answer and cannot take a new turn either.
   *
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session.
   *
   * @return bool
   *   TRUE when a new turn cannot start.
   */
  public function isBusy(ContentCreationSessionInterface $session): bool {
    // The run is read off the persistence under the id the agent runs with,
    // so no agent has to be built.
    $workflow = Workflow::make($session->editorialThread())->setPersistence($this->persistence);
    $run = $workflow->inspect();

    if ($run === NULL) {
      return FALSE;
    }

    return $run->status === WorkflowStatus::Suspended
      || ($run->status === WorkflowStatus::Running
        && ($run->leaseExpiresAt === NULL || $run->leaseExpiresAt > $this->time->getCurrentTime()));
  }

}
