<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Nodes;

use Drupal\oe_ai_assistant\Neuron\DeferredWorkflowInterface;
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
 * does its own work cannot report progress. The work of such a tool is a
 * workflow, and this node drives that run where it would have executed the
 * tool, forwarding what the run reports into the stream of the turn.
 *
 * Everything else is the tool node of Neuron: the approval gate, the call
 * budget, the one result message of the batch and the return to inference.
 */
final class WorkflowToolNode extends ToolNode {

  /**
   * {@inheritdoc}
   *
   * The branch is the only change: a tool with a workflow has it run here,
   * between the chunks that open and close the call, so what the run reports
   * reaches the editor while it works.
   */
  protected function executeLocalTools(array $calls, string $messageId, AgentState $state, ToolRegistry $tools): \Generator {
    $local = array_diff_key($calls, $this->filterDeferredCalls($calls));
    foreach ($local as $index => $call) {
      yield new ToolCallChunk($messageId, $call);
      // A rejected call runs nothing, and the tool node stops for one on its
      // own, so it answers that case for a tool with a workflow too.
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
   * This follows executeSingleTool(), which is where the policy of a call
   * lives: a rejected call does not run, the run counts against the call's
   * budget, the work is recorded at most once so a crash settles the call as
   * interrupted rather than running it twice, and a failure reaches the model
   * through the tool error handler.
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
        // A closure cannot yield on behalf of this generator, so the result
        // is recorded after the run rather than around it.
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
   * Drives the run of one call and reads what it answered.
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
   *   When the run shares the id of the turn, or pauses.
   */
  private function runWorkflowOf(ToolCall $call, AgentState $state, ToolRegistry $tools): \Generator {
    $tool = $this->findLiveTool($call->getName(), $tools);
    assert($tool instanceof WorkflowToolInterface);
    $workflow = $tool->workflow($call);

    // One id holds one live run, so a tool that handed back the turn's own
    // run would deadlock on itself.
    if ($workflow->getWorkflowId() === $state->getWorkflowId()) {
      throw new WorkflowException(sprintf(
        'The workflow of the "%s" tool runs under the id of the turn that called it.',
        $call->getName(),
      ));
    }

    // A run of its own is one step of this turn, and a step renews the lease
    // of the turn when it commits. This step commits after every call of the
    // reply, so a run that takes minutes renews it here.
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

    $result = $outcome->get(DeferredWorkflowInterface::RESULT_KEY);
    if (!is_array($result)) {
      throw new WorkflowException(sprintf(
        'The workflow of the "%s" tool stored no result.',
        $call->getName(),
      ));
    }

    return json_encode($result, JSON_THROW_ON_ERROR);
  }

}
