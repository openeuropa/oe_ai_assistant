<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Nodes;

use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentResult;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask;
use Drupal\oe_ai_assistant\Neuron\Tools\SubagentToolInterface;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolRegistry;

/**
 * Runs the subagent of a tool that has one, instead of executing the tool.
 *
 * A tool runs between two yields of the agent that called it, so a tool that
 * does its own work cannot report progress. A tool that implements
 * SubagentToolInterface hands its work to a subagent, and this node drives the
 * subagent in place of the call, yielding what it reports into the stream of
 * the turn.
 *
 * The rest is Neuron's ToolNode: the approval gate, the call budget, the one
 * result message of the batch and the return to inference.
 */
final class SubagentToolNode extends ToolNode {

  /**
   * The stream event that opens a subagent's run.
   */
  public const string STARTED_EVENT = 'subagent-started';

  /**
   * The stream event that reports a task queued to run in the background.
   */
  public const string QUEUED_EVENT = 'subagent-queued';

  /**
   * The stream event that closes a subagent's run.
   */
  public const string FINISHED_EVENT = 'subagent-finished';

  /**
   * {@inheritdoc}
   *
   * The parent executes every local call. The call of a subagent tool is
   * answered by its subagent instead, between the chunks that open and close
   * the call, so what the subagent reports reaches the editor while the call
   * is still open.
   */
  protected function executeLocalTools(array $calls, string $messageId, AgentState $state, ToolRegistry $tools): \Generator {
    $local = array_diff_key($calls, $this->filterDeferredCalls($calls));
    // A subagent reports its progress under the id of the call it answers,
    // and the stream frames its result under the same id. Two calls that
    // share an id, or carry none, report over each other and one result never
    // reaches the editor. The parent asks the same of a deferred call.
    $this->assertUniqueCallIds(array_filter($local, fn (ToolCall $call): bool => $this->isSubagentCall($call, $tools)));

    foreach ($local as $index => $call) {
      yield new ToolCallChunk($messageId, $call);
      // A rejected call must not run. executeSingleTool() returns early for
      // one and leaves its rejection result in place.
      if ($call->getApprovalState() !== ApprovalState::Rejected && $this->isSubagentCall($call, $tools)) {
        yield from $this->executeSubagent($call, $index, $state, $tools);
      }
      else {
        $this->executeSingleTool($call, $index, $state, $tools);
      }
      yield new ToolResultChunk($call);
    }

    return $local;
  }

  /**
   * Runs the subagent of one call, and settles the call with its result.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The call to answer.
   * @param int $index
   *   The position of the call in the reply, which names its records.
   * @param \NeuronAI\Agent\AgentState $state
   *   The state of the turn.
   * @param \NeuronAI\Tools\ToolRegistry $tools
   *   The tools the model was offered.
   *
   * @return \Generator
   *   Yields what the subagent reports.
   */
  protected function executeSubagent(ToolCall $call, int $index, AgentState $state, ToolRegistry $tools): \Generator {
    $this->emit(new ToolCalling($call));

    $memoKey = 'tool.' . ($call->getCallId() ?? $call->getName()) . '.' . $index;

    // The policy is the one of executeSingleTool(): the call counts against
    // the tool's budget, a recorded result is restored rather than run again,
    // and a failure goes to the tool error handler, whose answer the model
    // reads.
    try {
      $this->checkToolRuns($call, $state, $tools);

      $result = $this->recallMemo($memoKey);
      if ($result === NULL) {
        $result = yield from $this->answer($call, $index, $state, $tools, $memoKey . '.started');
        $result = $this->memoize($memoKey, static fn (): string|ToolOutput => $result);
      }

      $call->setResult($result);
    }
    catch (\Throwable $e) {
      // The batch's result message records this outcome, so a replay must
      // restore it rather than run the call again.
      $call->setResult($this->memoize($memoKey, function () use ($e, $call): string|ToolOutput {
        $this->handleError($e, $call);

        return $call->getResult();
      }));
    }
    finally {
      $this->emit(new ToolCalled($call));
    }
  }

  /**
   * Answers a call that has no recorded result yet.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The call to answer.
   * @param int $index
   *   The position of the call in the reply.
   * @param \NeuronAI\Agent\AgentState $state
   *   The state of the turn.
   * @param \NeuronAI\Tools\ToolRegistry $tools
   *   The tools the model was offered.
   * @param string $startedKey
   *   The memo that records that the subagent started.
   *
   * @return \Generator
   *   Yields what the subagent reports. Returns the result of the call.
   */
  private function answer(ToolCall $call, int $index, AgentState $state, ToolRegistry $tools, string $startedKey): \Generator {
    // memoizeAtMostOnce() can't wrap a run that yields, because its closure
    // would have to yield on behalf of this generator. So its two records are
    // written here: the start before the subagent runs, the result after. A
    // replay that finds the start but no result cannot tell whether the work
    // landed, and settles the call as interrupted instead of running it again.
    $tool = $this->resolveTool($call, $tools);
    assert($tool instanceof SubagentToolInterface);
    $definition = $tool->definition();
    $task = $this->task($call, $index, $state, $definition);

    if ($this->recallMemo($startedKey) === TRUE) {
      $tool->close($task, new SubagentResult(SubagentResult::FAILED, ['error' => 'The task was interrupted before its result was recorded.']));

      return $this->interruptedResult($call);
    }

    // The gateway does not enforce the tool's schema. An invalid call is
    // answered with the reason, so the model can correct it, and the subagent
    // never starts.
    $error = $tool->inputError() ?? $definition->validate($task);
    if ($error !== NULL) {
      return ToolOutput::error($error);
    }

    // The ledger refuses a continuation of a task that cannot be continued.
    $opened = $tool->open($task);
    if (is_string($opened)) {
      return ToolOutput::error($opened);
    }
    $task = $opened;

    // A background task is queued when the turn ends. The call is answered
    // with a handle now, and the ledger row is what the worker runs from.
    if ($task->background) {
      yield new CustomStreamEvent(self::QUEUED_EVENT, $this->label($task, $definition));

      return json_encode(
        (new SubagentResult(SubagentResult::PENDING, ['note' => 'The task runs in the background. Its result arrives later in a system message; do not wait for it and do not call this tool again for it.']))->toArray($task->taskId, $definition->name),
        JSON_THROW_ON_ERROR,
      );
    }

    $this->memoize($startedKey, static fn (): bool => TRUE);

    try {
      $result = yield from $this->runSubagent($definition, $task);
    }
    catch (\Throwable $e) {
      $tool->close($task, new SubagentResult(SubagentResult::FAILED, ['error' => 'The subagent failed.']));
      // The run opened a card in the stream, and the failure closes it.
      yield new CustomStreamEvent(self::FINISHED_EVENT, $this->label($task, $definition) + ['status' => SubagentResult::FAILED]);
      throw $e;
    }
    $tool->close($task, $result);

    return json_encode($result->toArray($task->taskId, $definition->name), JSON_THROW_ON_ERROR);
  }

  /**
   * Drives the subagent of one task.
   *
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition $definition
   *   The subagent.
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask $task
   *   The task.
   *
   * @return \Generator
   *   Yields what the subagent reports. Returns the SubagentResult.
   */
  private function runSubagent(SubagentDefinition $definition, SubagentTask $task): \Generator {
    $label = $this->label($task, $definition);

    yield new CustomStreamEvent(self::STARTED_EVENT, $label);

    // The turn's lease is renewed when a step commits, and this step commits
    // only after every call of the reply has run. A subagent can take minutes,
    // so the lease is renewed as it reports. heartbeat() only writes once half
    // the lease has passed, so calling it on every report is cheap.
    $this->heartbeat();
    $reports = $definition->subagent->run($task);
    foreach ($reports as $report) {
      $this->heartbeat();
      yield $report;
    }
    $result = $reports->getReturn();
    assert($result instanceof SubagentResult);

    yield new CustomStreamEvent(self::FINISHED_EVENT, $label + ['status' => $result->status]);

    return $result;
  }

  /**
   * Builds the label that names a task in the events of the stream.
   */
  private function label(SubagentTask $task, SubagentDefinition $definition): array {
    return [
      'callId' => $task->callId,
      'taskId' => $task->taskId,
      'subagent' => $definition->name,
    ];
  }

  /**
   * Builds the task of one call.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The call.
   * @param int $index
   *   The position of the call in the reply.
   * @param \NeuronAI\Agent\AgentState $state
   *   The state of the turn.
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition $definition
   *   The subagent that answers the call.
   *
   * @return \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask
   *   The task.
   */
  private function task(ToolCall $call, int $index, AgentState $state, SubagentDefinition $definition): SubagentTask {
    $callId = (string) $call->getCallId();
    $prompt = $call->getInput($definition->promptProperty);
    $continued = $call->getInput('task_id');

    // A call that names a task continues it, when the subagent can be.
    $continuation = $definition->continuable && is_string($continued) && trim($continued) !== '';

    return new SubagentTask(
      $continuation ? trim($continued) : self::taskId((string) $state->getWorkflowId(), (string) $state->getRunId(), $callId, $index),
      $callId,
      is_string($prompt) ? $prompt : '',
      $call->getInputs(),
      $continuation,
      background: $definition->background && in_array($call->getInput('run_in_background'), [TRUE, 'true', 1], TRUE),
    );
  }

  /**
   * Derives the id of the task that answers a call.
   *
   * The id is the same on every pass over the call, so the child's run, and
   * the records it keeps, are found again when the step is replayed.
   *
   * @param string $workflowId
   *   The id of the turn's workflow.
   * @param string $runId
   *   The id of the turn's run.
   * @param string $callId
   *   The id of the call.
   * @param int $index
   *   The position of the call in the reply.
   *
   * @return string
   *   The task id: "sub_" and 24 hexadecimal characters.
   */
  public static function taskId(string $workflowId, string $runId, string $callId, int $index): string {
    return 'sub_' . substr(hash('sha256', implode("\0", [$workflowId, $runId, $callId, (string) $index])), 0, 24);
  }

  /**
   * Whether a subagent answers a call.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The call.
   * @param \NeuronAI\Tools\ToolRegistry $tools
   *   The tools the model was offered.
   *
   * @return bool
   *   TRUE when the called tool hands its work to a subagent.
   */
  private function isSubagentCall(ToolCall $call, ToolRegistry $tools): bool {
    // find() rather than findLiveTool(): a call to a tool the registry does
    // not have goes to executeSingleTool(), whose error handling answers it.
    return $tools->find($call->getName()) instanceof SubagentToolInterface;
  }

}
