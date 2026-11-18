<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Nodes;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Neuron\DeferredWorkflowInterface;
use Drupal\oe_ai_assistant\Plugin\NeuronTool\RequestDraftNeuronTool;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AwaitToolResultsEvent;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Agent\Nodes\AwaitToolResultsNode;
use NeuronAI\Exceptions\NeuronException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeContext;

/**
 * Runs the workflow that answers each deferred call of a reply.
 *
 * A deferred tool runs no code of its own. ToolNode passes its calls to this
 * node instead of running them, which is what lets the work stream its
 * progress to the editor. Nobody outside the run submits these results, so
 * this node starts the workflow that does the work and forwards what it
 * reports.
 *
 * Answering the batch is Neuron's own behaviour. AwaitToolResultsNode reads
 * the answer of a call off the record this node leaves, the same record it
 * reads when a result arrives one at a time, so it shapes every result,
 * writes the one message the model reads and returns the turn to inference.
 * This node adds only the work that produces the answers.
 *
 * Neuron reports every deferred call of a reply on one AwaitToolResultsEvent,
 * and a graph takes one node per event class, so this one node answers them
 * all.
 */
final class DeferredCallsNode extends Node implements AgentNodeInterface {

  /**
   * Builds the node.
   *
   * @param \Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface $workflows
   *   Builds the workflow that answers a call.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Records a workflow that could not answer.
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the turn serves.
   * @param \NeuronAI\Agent\Nodes\AwaitToolResultsNode $inner
   *   The node this one stands in for, which answers the batch.
   */
  public function __construct(
    private readonly NeuronWorkflowManagerInterface $workflows,
    private readonly LoggerChannelInterface $logger,
    private readonly ContentCreationSessionInterface $session,
    private readonly AwaitToolResultsNode $inner = new AwaitToolResultsNode(),
  ) {}

  /**
   * {@inheritdoc}
   *
   * The executor hands the context only to the nodes of the graph, and the
   * inner node is not one of them. It reads the records this node leaves, and
   * reports the message it writes, so it runs in the step this node runs in.
   */
  public function setWorkflowContext(NodeContext $context): void {
    parent::setWorkflowContext($context);
    $this->inner->setWorkflowContext($context);
  }

  /**
   * Answers every deferred call of the reply, then settles the batch.
   */
  public function __invoke(AwaitToolResultsEvent $event, AgentState $state, AgentResources $resources): \Generator {
    // nextVersion() counts the drafts the session stores, and a draft is
    // stored only once its last group is written. Two drafts of one reply
    // running side by side would both be stored as "Draft 1". So this loop
    // answers one call at a time.
    foreach ($event->deferredCalls as $call) {
      $callId = (string) $call->getCallId();
      // The record is where the inner node reads the answer of a call, and a
      // step that runs again reads back what it wrote rather than running the
      // workflow a second time. We can't wrap the work in memoize(), because
      // its closure would have to yield on behalf of this generator.
      if ($this->recallMemo('result.' . $callId) === NULL) {
        $answer = yield from $this->start($call, $callId);
        $this->memoize('result.' . $callId, static fn (): array => $answer);
      }

      // A draft takes minutes, and a run renews its lease when a step
      // commits. This step commits once, after every call of the reply, so
      // the lease is renewed here instead.
      $this->heartbeat();
    }

    return yield from ($this->inner)($event, $state, $resources);
  }

  /**
   * Runs the workflow of one call, and answers what the model reads.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The deferred call to answer.
   * @param string $callId
   *   The id of the call, which names the progress the editor watches.
   *
   * @return \Generator
   *   Yields what the workflow reports. Returns the answer of the call, in
   *   the shape Neuron reads: one result, or one error.
   */
  private function start(ToolCall $call, string $callId): \Generator {
    // ToolNode defers a call only when its tool declared itself deferred,
    // and this module declares one. A second one naming no workflow would be
    // answered by nothing at all, so it is refused here.
    if ($call->getName() !== RequestDraftNeuronTool::TOOL_ID) {
      throw new \LogicException(sprintf(
        'The "%s" tool is deferred and names no workflow to answer its calls.',
        $call->getName(),
      ));
    }

    try {
      $reports = $this->workflows->createWorkflow('draft_content', [], [
        'session' => $this->session,
        'call' => $callId,
      ])->events();
      foreach ($reports as $report) {
        yield $report;
      }
      $final = $reports->getReturn();
    }
    catch (NeuronException | PluginException $e) {
      return $this->failed($callId, $e->getMessage(), $e);
    }

    // A workflow of this module answers every call it is given, so a run that
    // paused or stored nothing is a fault of ours. The model still has to
    // read something, or the turn ends on a call with no answer.
    if ($final->isInterrupted()) {
      return $this->failed($callId, 'the run paused and nothing answered it');
    }

    $result = $final->get(DeferredWorkflowInterface::RESULT_KEY);
    if (!is_array($result)) {
      return $this->failed($callId, 'the run stored no result');
    }

    return ['result' => $result];
  }

  /**
   * Records a workflow that could not answer, and what the model is told.
   *
   * The reason can carry a provider response or a query, and the model
   * repeats to the editor what it reads. So the log keeps the reason and the
   * model is told only that the tool failed.
   *
   * @param string $callId
   *   The call that went unanswered.
   * @param string $reason
   *   What went wrong, for the log.
   * @param \Throwable|null $exception
   *   The exception, when one was thrown.
   *
   * @return array
   *   The answer of the call.
   */
  private function failed(string $callId, string $reason, ?\Throwable $exception = NULL): array {
    $this->logger->error('The draft_content workflow did not answer call @call of session @session: @reason', [
      '@call' => $callId,
      '@session' => (string) $this->session->id(),
      '@reason' => $reason,
    ] + ($exception === NULL ? [] : ['exception' => $exception]));

    return ['error' => 'The tool failed.'];
  }

}
