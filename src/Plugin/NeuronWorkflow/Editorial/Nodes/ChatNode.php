<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Nodes;

use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\EditorialResources;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events\ChatTurnEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;

/**
 * Starts the turn by sending the editor's message to the agent.
 *
 * The node yields what the agent streams, so the chunks reach the stream of
 * the run and the adapter of the run shapes them. The agent answers or asks
 * for a draft, and the returned event says which.
 */
final class ChatNode extends AgentNode {

  /**
   * Streams the agent's answer to one message of the editor.
   */
  public function __invoke(ChatTurnEvent $event, WorkflowState $state, EditorialResources $resources): \Generator {
    $this->dropSuspendedRun($resources);

    // The node that drafts never receives the editor's message. A drafter
    // holds only its own thread, which carries no message of the editor.
    // So this node stores the message on the state as the task of the turn.
    $state->set('task', $event->message);

    $agentState = yield from $this->agent->stream(new UserMessage($event->message));

    return $this->route($agentState, $state);
  }

  /**
   * Frees the conversation of a turn that asked for a draft and never read it.
   *
   * The call that asks for a draft suspends the agent until the same request
   * submits the result. A process that ends in that window leaves a run that
   * nothing will resume, and a held run refuses every turn after it. So the
   * turn drops it, and the agent reads the open call as interrupted.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\EditorialResources $resources
   *   What this run shares.
   */
  private function dropSuspendedRun(EditorialResources $resources): void {
    $run = $this->agent->inspect();
    if ($run?->status !== WorkflowStatus::Suspended) {
      return;
    }

    // The agent refuses to drop a run that waits on a tool call. A person
    // can still answer one, and dropping the run answers it with an error.
    // Nobody will answer this call, because the request that asked is gone.
    // So the drop goes through an engine built on the agent's persistence.
    (new WorkflowEngine($resources->persistence))
      ->abandon((string) $this->agent->getWorkflowId());

    $resources->logger->notice('Dropped the suspended run @run of session @session. It asked for a draft and nothing submitted the result.', [
      '@run' => $run->runId,
      '@session' => (string) $resources->session->id(),
    ]);
  }

}
