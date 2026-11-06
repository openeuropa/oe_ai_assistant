<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Nodes;

use Drupal\oe_ai_assistant\Neuron\Workflow\Events\ChatTurnEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\WorkflowState;

/**
 * Starts the turn by sending the editor's message to the agent.
 *
 * The node yields what the agent streams, so the chunks reach the stream of
 * the run and the adapter of the run shapes them. The agent answers or asks
 * for a draft, and the returned event says which.
 */
final class ChatNode extends EditorialAgentNode {

  /**
   * Streams the agent's answer to one message of the editor.
   */
  public function __invoke(ChatTurnEvent $event, WorkflowState $state): \Generator {
    // The node that drafts and the node that resumes the turn both read these,
    // and only this node receives them. A drafter reads its own thread only,
    // so the message is on the state as the task of the turn.
    $state->set('version', $event->version);
    $state->set('task', $event->message);

    $agentState = yield from $this->agent()->stream(new UserMessage($event->message));

    return $this->route($agentState, $state);
  }

}
