<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Subagent;

use Drupal\oe_ai_assistant\Neuron\ToolWorkflowInterface;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Workflow;

/**
 * A subagent whose work is a workflow that leaves its answer in its state.
 */
final class WorkflowSubagent implements SubagentInterface {

  /**
   * Builds the subagent.
   *
   * @param \Closure $factory
   *   Builds the workflow for a task: fn (SubagentTask $task): Workflow. The
   *   workflow id must name the task, so a later start of the same task finds
   *   its run.
   * @param int $leaseTimeout
   *   How long, in seconds, the run holds its claim between two commits.
   */
  public function __construct(
    private readonly \Closure $factory,
    private readonly int $leaseTimeout = 600,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(SubagentTask $task): \Generator {
    $workflow = ($this->factory)($task);
    assert($workflow instanceof Workflow);

    // A plain workflow holds no lease, so a run whose process died would look
    // alive forever. The lease lets the engine tell a dead run from a live one.
    $workflow->setLeaseTimeout($this->leaseTimeout);

    // The run id is reserved from the task id. A start under the same id finds
    // the run again instead of starting a second one, and recovers it when it
    // failed or its lease ran out.
    $reports = $workflow->events(ExecutionRequest::start(runId: $task->taskId, recoverFailed: TRUE));
    foreach ($reports as $report) {
      yield $report;
    }
    $outcome = $reports->getReturn();

    // Nothing outside this run answers a pause, so a run that paused leaves
    // the task with no answer.
    if ($outcome->isInterrupted()) {
      throw new WorkflowException(sprintf('The workflow of task "%s" paused, and nothing can answer it.', $task->taskId));
    }

    $result = $outcome->get(ToolWorkflowInterface::RESULT_KEY);
    if (!is_array($result)) {
      throw new WorkflowException(sprintf('The workflow of task "%s" stored no result.', $task->taskId));
    }

    return SubagentResult::fromWorkflow($result);
  }

}
