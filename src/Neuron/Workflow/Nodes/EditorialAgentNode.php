<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Nodes;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Workflow\Events\DraftingRequestedEvent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Base of the two nodes that drive the agent the editor talks to.
 *
 * A node ends when it returns an event, so the node that resumes the turn is
 * not the one that started it. Both build the agent from the same plugin and
 * the same session, which is how the second one finds the run the first one
 * suspended.
 */
abstract class EditorialAgentNode extends Node {

  use DependencySerializationTrait;

  /**
   * Builds the node.
   *
   * @param \Drupal\ai_neuron\Agent\NeuronAgentManagerInterface $agents
   *   Builds the agent of this run's conversation.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the conversation belongs to.
   */
  public function __construct(
    protected NeuronAgentManagerInterface $agents,
    protected AiEditorialSessionInterface $session,
  ) {}

  /**
   * Builds the agent of this run's conversation.
   *
   * @return \NeuronAI\Agent\AgentInterface
   *   The agent. The manager gives it the provider, the conversation store,
   *   the persistence and the listeners of the site.
   */
  protected function agent(): AgentInterface {
    return $this->agents->createAgent('drafting', ['session' => $this->session]);
  }

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
   *   the run when it answered.
   */
  protected function route(AgentState $agentState, WorkflowState $state): Event {
    $request = $agentState->getInterruptRequest();

    if ($request instanceof ToolResultsRequest) {
      $callIds = [];
      foreach ($request->getToolCalls() as $call) {
        $callIds[] = (string) $call->getCallId();
      }

      return new DraftingRequestedEvent($callIds, (int) $state->get('version'));
    }

    $state->set('answer', (string) $agentState->getMessage()?->getContent());

    return new StopEvent();
  }

}
