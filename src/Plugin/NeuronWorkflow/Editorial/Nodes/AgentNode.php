<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Nodes;

use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events\DraftingRequestedEvent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Base of the nodes that drive the agent the editor talks to.
 *
 * A node ends when it returns an event, so a node that resumes the turn is
 * not the one that started it. They build the agent from the same plugin and
 * the same session, which is how a later one finds the run an earlier one
 * suspended.
 */
abstract class AgentNode extends Node {

  /**
   * Builds the node.
   *
   * The agent is built with the run rather than here. Building it resolves
   * the session's template and the provider, and the endpoint has to answer a
   * stale template or a misconfigured provider before it opens the stream: an
   * exception raised once the response is streaming reaches the editor as
   * Drupal's own error page, mid-protocol. The chat nodes share the one
   * instance, which is also how a node that resumes the turn reaches the
   * call the node that started it suspended on.
   *
   * @param \NeuronAI\Agent\AgentInterface $agent
   *   The agent of this run's conversation.
   */
  public function __construct(
    protected AgentInterface $agent,
  ) {}

  /**
   * Decides what the run does with the turn the agent just ran.
   *
   * @param \NeuronAI\Agent\AgentState $agentState
   *   What the agent's turn ended with.
   * @param \NeuronAI\Workflow\WorkflowState $state
   *   The state of the run.
   *
   * @return \NeuronAI\Workflow\Events\Event
   *   The drafting request when the agent waits for a draft, and the end of
   *   the run otherwise: it answered, or it waits for the editor's decision.
   */
  protected function route(AgentState $agentState, WorkflowState $state): Event {
    $request = $agentState->getInterruptRequest();

    if ($request instanceof ToolResultsRequest) {
      $callIds = [];
      foreach ($request->getToolCalls() as $call) {
        $callIds[] = (string) $call->getCallId();
      }

      return new DraftingRequestedEvent($callIds);
    }

    $state->set('answer', (string) $agentState->getMessage()?->getContent());

    return new StopEvent();
  }

}
