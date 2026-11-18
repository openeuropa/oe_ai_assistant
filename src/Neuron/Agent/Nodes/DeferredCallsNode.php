<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Nodes;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\oe_ai_assistant\Neuron\Agent\EditorialResources;
use Drupal\oe_ai_assistant\Plugin\NeuronTool\RequestDraftNeuronTool;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AwaitToolResultsEvent;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Agent\Nodes\AwaitToolResultsNode;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\NeuronException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeContext;

/**
 * Starts the workflow that answers each deferred call, and settles the batch.
 *
 * A deferred tool runs no code of its own. ToolNode passes its calls to this
 * node instead of running them, which is what lets the work stream its
 * progress to the editor. Nobody outside the run submits these results, so
 * this node starts the workflow that does the work and forwards what it
 * reports.
 *
 * Merging the answered batch into one result message is Neuron's own
 * behaviour. AwaitToolResultsNode does only that when it is handed an event
 * whose calls all carry a result, so this node delegates to it rather than
 * repeating it.
 *
 * Neuron reports every deferred call of a reply on one AwaitToolResultsEvent,
 * and a graph takes one node per event class, so this one node maps each
 * deferred tool to its workflow.
 */
final class DeferredCallsNode extends Node implements AgentNodeInterface {

  /**
   * The state key a workflow leaves the tool result under.
   */
  public const string RESULT_KEY = 'result';

  /**
   * Builds the node.
   *
   * @param \NeuronAI\Agent\Nodes\AwaitToolResultsNode $inner
   *   The node this one stands in for, which settles the batch.
   */
  public function __construct(
    private readonly AwaitToolResultsNode $inner = new AwaitToolResultsNode(),
  ) {}

  /**
   * {@inheritdoc}
   *
   * The executor hands the context only to the nodes of the graph, and the
   * inner node is not one of them. It records the result message it writes,
   * and reports that write, so it runs in the step this node runs in.
   */
  public function setWorkflowContext(NodeContext $context): void {
    parent::setWorkflowContext($context);
    $this->inner->setWorkflowContext($context);
  }

  /**
   * Answers every deferred call of the reply, then settles the batch.
   *
   * The event this returns sends the turn back to the model, which is what
   * tells the editor what the work produced.
   */
  public function __invoke(AwaitToolResultsEvent $event, AgentState $state, EditorialResources $resources): \Generator {
    // nextVersion() counts the drafts the session stores, and a draft is
    // stored only once its last group is written. Two drafts of one reply
    // running side by side would both be stored as "Draft 1". So this loop
    // answers one call at a time.
    foreach ($event->deferredCalls as $call) {
      $callId = (string) $call->getCallId();
      // A replayed step would run the whole workflow a second time, so a
      // call that already has a recorded answer reads it back. We can't wrap
      // the work in memoize(), because its closure would have to yield on
      // behalf of this generator.
      $answer = $this->recallMemo('result.' . $callId);
      if (!is_array($answer)) {
        $answer = yield from $this->start($call, $callId, $resources);
        $answer = $this->memoize('result.' . $callId, static fn (): array => $answer);
      }

      $call->setResult($this->output($answer));
      yield new ToolResultChunk($call);
      $this->emit(new ToolCalled($call));
    }

    // Every call now carries a result, so the node that would wait has
    // nothing to wait for and only settles the batch.
    return yield from ($this->inner)(
      new AwaitToolResultsEvent(
        completedCalls: $event->completedCalls + $event->deferredCalls,
        deferredCalls: [],
      ),
      $state,
      $resources,
    );
  }

  /**
   * Runs the workflow of one call, and answers what the model reads.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The deferred call to answer.
   * @param string $callId
   *   The id of the call, which names the progress the editor watches.
   * @param \Drupal\oe_ai_assistant\Neuron\Agent\EditorialResources $resources
   *   What this run shares.
   *
   * @return \Generator
   *   Yields what the workflow reports. Returns the answer of the call, in
   *   the shape Neuron reads: one result, or one error.
   *
   * @throws \NeuronAI\Workflow\Interrupt\WorkflowInterrupt
   *   When the workflow pauses. Only the run can answer that, so it travels.
   */
  private function start(ToolCall $call, string $callId, EditorialResources $resources): \Generator {
    [$plugin, $contexts] = $this->workflowOf($call, $callId, $resources);

    try {
      $reports = $resources->workflows->createWorkflow($plugin, [], $contexts)->events();
      foreach ($reports as $report) {
        yield $report;
      }
      $final = $reports->getReturn();
    }
    catch (WorkflowInterrupt $e) {
      throw $e;
    }
    catch (NeuronException | PluginException $e) {
      return $this->failed($resources, $plugin, $callId, $e->getMessage(), $e);
    }

    // A workflow of this module answers every call it is given, so a run that
    // paused or stored nothing is a fault of ours. The model still has to
    // read something, or the turn ends on a call with no answer.
    if ($final->isInterrupted()) {
      return $this->failed($resources, $plugin, $callId, 'the run paused and nothing answered it');
    }

    $result = $final->get(self::RESULT_KEY);
    if (!is_array($result)) {
      return $this->failed($resources, $plugin, $callId, 'the run stored no result');
    }

    return ['result' => $result];
  }

  /**
   * Records a workflow that could not answer, and what the model is told.
   *
   * The reason can carry a provider response or a query, and the model
   * repeats to the editor what it reads. So the log keeps the reason and the
   * model is told which tool failed.
   *
   * @param \Drupal\oe_ai_assistant\Neuron\Agent\EditorialResources $resources
   *   What this run shares.
   * @param string $plugin
   *   The workflow that was asked.
   * @param string $callId
   *   The call it was asked for.
   * @param string $reason
   *   What went wrong, for the log.
   * @param \Throwable|null $exception
   *   The exception, when one was thrown.
   *
   * @return array
   *   The answer of the call.
   */
  private function failed(
    EditorialResources $resources,
    string $plugin,
    string $callId,
    string $reason,
    ?\Throwable $exception = NULL,
  ): array {
    $resources->logger->error('The @workflow workflow did not answer call @call of session @session: @reason', [
      '@workflow' => $plugin,
      '@call' => $callId,
      '@session' => (string) $resources->session->id(),
      '@reason' => $reason,
    ] + ($exception === NULL ? [] : ['exception' => $exception]));

    return ['error' => 'The tool failed.'];
  }

  /**
   * What the call carries back to the model.
   *
   * @param array $answer
   *   One result, or one error, as start() produced it.
   *
   * @return string|\NeuronAI\Tools\ToolOutput
   *   The result of the call, shaped the way Neuron's own node shapes it.
   */
  private function output(array $answer): string|ToolOutput {
    if (isset($answer['error'])) {
      return ToolOutput::error((string) $answer['error']);
    }

    $result = $answer['result'] ?? NULL;

    return is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR);
  }

  /**
   * The workflow that answers one tool, and the contexts it runs with.
   *
   * A tool whose work runs in a workflow declares itself deferred, and
   * ToolNode refuses a call of a tool it cannot run. So a name with no entry
   * here is a tool of this module that declared itself deferred and named no
   * workflow.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The deferred call to answer.
   * @param string $callId
   *   The id of the call.
   * @param \Drupal\oe_ai_assistant\Neuron\Agent\EditorialResources $resources
   *   What this run shares.
   *
   * @return array
   *   The plugin id of the workflow, and the contexts to build it with.
   */
  private function workflowOf(ToolCall $call, string $callId, EditorialResources $resources): array {
    $session = $resources->session;

    return match ((string) $call->getName()) {
      RequestDraftNeuronTool::TOOL_ID => [
        'draft_content',
        ['session' => $session, 'call' => $callId],
      ],
      default => throw new \LogicException(sprintf(
        'The "%s" tool is deferred and names no workflow to answer its calls.',
        $call->getName(),
      )),
    };
  }

}
