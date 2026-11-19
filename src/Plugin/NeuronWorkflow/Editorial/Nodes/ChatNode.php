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

    $agentState = yield from $this->agent->stream(new UserMessage($event->message));

    return $this->route($agentState, $state);
  }

  /**
   * Frees the conversation of a run that waits on a call nobody will answer.
   *
   * A run that stays suspended makes every later turn of the session fail.
   * The editor sends a message and reads an error. A run reaches that state
   * when the process dies after the call that asks for a draft, before the
   * same request submits the result, or when the editor sends a message
   * while a save waits for a decision. So this turn drops the run, and the
   * agent reads the open call as interrupted.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\EditorialResources $resources
   *   What this run shares.
   */
  private function dropSuspendedRun(EditorialResources $resources): void {
    $run = $this->agent->inspect();
    if ($run?->status !== WorkflowStatus::Suspended) {
      return;
    }

    // The agent refuses to drop a run that waits on a tool call. A later
    // turn answers such a call with an interruption error, and a person who
    // meant to submit the result loses the chance. Nobody submits this
    // result, because the request that asked for the draft is gone, and the
    // editor who sent a message has left the waiting save undecided. So an
    // engine built on the agent's persistence drops the run.
    (new WorkflowEngine($resources->persistence))
      ->abandon((string) $this->agent->getWorkflowId());

    $resources->logger->notice('Dropped the suspended run @run of session @session. It waited on a call that nothing answered.', [
      '@run' => $run->runId,
      '@session' => (string) $resources->session->id(),
    ]);
  }

}
