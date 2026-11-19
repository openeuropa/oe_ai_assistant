<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Nodes;

use Drupal\oe_ai_assistant\Neuron\ToolWorkflowInterface;
use Drupal\oe_ai_assistant\Neuron\Tools\WorkflowToolInterface;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolRegistry;

/**
 * Runs the workflow of a tool that has one, instead of executing the tool.
 *
 * A tool runs between two yields of the agent that called it, so a tool that
 * does its own work cannot report progress. A tool that implements
 * WorkflowToolInterface puts that work in a workflow, and this node drives
 * the run in place of the call, yielding what it reports into the stream of
 * the turn.
 *
 * The rest is Neuron's ToolNode. The approval gate, the call budget, the one
 * result message of the batch and the return to inference are all the
 * parent's.
 */
final class WorkflowToolNode extends ToolNode {

  /**
   * {@inheritdoc}
   *
   * The parent executes every local call, and a tool whose work is a workflow
   * has nothing to execute. Its run is driven here instead, between the
   * chunks that open and close the call, so what it reports reaches the
   * editor while the call is still open.
   */
  protected function executeLocalTools(array $calls, string $messageId, AgentState $state, ToolRegistry $tools): \Generator {
    $local = array_diff_key($calls, $this->filterDeferredCalls($calls));
    foreach ($local as $index => $call) {
      yield new ToolCallChunk($messageId, $call);
      // A rejected call must not run. executeSingleTool() returns early for
      // one and leaves its rejection result in place, so a rejected call
      // goes there even when its tool has a workflow.
      if ($call->getApprovalState() !== ApprovalState::Rejected
        && $this->findLiveTool($call->getName(), $tools) instanceof WorkflowToolInterface) {
        yield from $this->executeToolWorkflow($call, $index, $state, $tools);
      }
      else {
        $this->executeSingleTool($call, $index, $state, $tools);
      }
      yield new ToolResultChunk($call);
    }

    return $local;
  }

  /**
   * Runs the workflow of one call, and settles the call with its result.
   *
   * The policy of a call is the one in executeSingleTool(). The run counts
   * against the tool's budget, and a result already recorded is restored
   * rather than run again. A failure goes to the tool error handler, and what
   * the handler returns is what the model reads.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The call to answer.
   * @param int $index
   *   The position of the call in the reply, which names its record.
   * @param \NeuronAI\Agent\AgentState $state
   *   The state of the turn.
   * @param \NeuronAI\Tools\ToolRegistry $tools
   *   The tools the model was offered.
   *
   * @return \Generator
   *   Yields what the run reports.
   */
  protected function executeToolWorkflow(ToolCall $call, int $index, AgentState $state, ToolRegistry $tools): \Generator {
    $this->emit(new ToolCalling($call));

    $memoKey = 'tool.' . ($call->getCallId() ?? $call->getName()) . '.' . $index;

    try {
      $this->checkToolRuns($call, $state, $tools);

      $result = $this->recallMemo($memoKey);
      if ($result === NULL) {
        $result = yield from $this->runWorkflowOf($call, $state, $tools);
        // We can't wrap the run in memoize(), because its closure would
        // have to yield on behalf of this generator. So the result is
        // recorded once the run is over.
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
   * Drives the workflow of one call and reads the result off its state.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The call to answer.
   * @param \NeuronAI\Agent\AgentState $state
   *   The state of the turn.
   * @param \NeuronAI\Tools\ToolRegistry $tools
   *   The tools the model was offered.
   *
   * @return \Generator
   *   Yields what the run reports. Returns the result of the call.
   *
   * @throws \NeuronAI\Exceptions\WorkflowException
   *   When the run cannot answer the call.
   */
  private function runWorkflowOf(ToolCall $call, AgentState $state, ToolRegistry $tools): \Generator {
    $tool = $this->findLiveTool($call->getName(), $tools);
    assert($tool instanceof WorkflowToolInterface);
    $workflow = $tool->workflow($call);

    // One workflow id holds one live run, and the turn is holding its own.
    // The engine would refuse a run under that id, with a message about a
    // run in flight rather than about the tool that asked for it. Neither
    // side is required to carry an id, and two runs without one do not
    // collide.
    $turnId = $state->getWorkflowId();
    if ($turnId !== NULL && $workflow->getWorkflowId() === $turnId) {
      throw new WorkflowException(sprintf(
        'The workflow of the "%s" tool runs under the id of the turn that called it.',
        $call->getName(),
      ));
    }

    // The turn's lease is renewed when a step commits, and this step commits
    // only after every call of the reply has run. A tool's run can take
    // minutes, so the lease is renewed here before it starts.
    $this->heartbeat();

    $reports = $workflow->events();
    foreach ($reports as $report) {
      yield $report;
    }
    $outcome = $reports->getReturn();

    // Nothing outside this request answers the call, so a run that paused
    // leaves it unanswerable.
    if ($outcome->isInterrupted()) {
      throw new WorkflowException(sprintf(
        'The workflow of the "%s" tool paused, and nothing can answer it.',
        $call->getName(),
      ));
    }

    $result = $outcome->get(ToolWorkflowInterface::RESULT_KEY);
    if (!is_array($result)) {
      throw new WorkflowException(sprintf(
        'The workflow of the "%s" tool stored no result.',
        $call->getName(),
      ));
    }

    return json_encode($result, JSON_THROW_ON_ERROR);
  }

}
