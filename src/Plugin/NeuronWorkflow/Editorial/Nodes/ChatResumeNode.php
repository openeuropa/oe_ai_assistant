<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Nodes;

use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events\DraftStoredEvent;
use NeuronAI\Workflow\WorkflowState;

/**
 * Hands the written draft to the agent and streams what it answers.
 *
 * The agent of this run waits on the call that asked for the draft.
 * Submitting the results continues that turn, so the draft reaches the model
 * as the result of its own call. Starting a turn here would ask the editor's
 * question a second time instead.
 */
final class ChatResumeNode extends AgentNode {

  /**
   * Streams what the agent answers once it reads the draft.
   */
  public function __invoke(DraftStoredEvent $event, WorkflowState $state): \Generator {
    $agentState = yield from $this->agent
      ->submitToolResults($state->get('results'))
      ->events();

    return $this->route($agentState, $state);
  }

}
