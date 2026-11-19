<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Nodes;

use Drupal\oe_ai_assistant\Plugin\NeuronTool\SaveDraftNeuronTool;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events\ApprovalDecisionEvent;
use NeuronAI\Workflow\WorkflowState;

/**
 * Hands the editor's decision to the agent and streams what it answers.
 *
 * The editor's decision resumes the agent's suspended call, the way
 * ChatResumeNode resumes a deferred one. An approved call runs. A cancelled
 * call answers the model with the cancel text instead.
 */
final class ApprovalNode extends AgentNode {

  /**
   * Streams what the agent answers once it reads the decision.
   */
  public function __invoke(ApprovalDecisionEvent $event, WorkflowState $state): \Generator {
    $answer = $event->decision === 'approve' ? 'approve' : ['reject', SaveDraftNeuronTool::CANCELLED];

    $agentState = yield from $this->agent
      ->submitApprovalDecisions([$event->callId => $answer])
      ->events();

    return $this->route($agentState, $state);
  }

}
