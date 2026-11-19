<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Starts a turn with the editor's decision on a call that waits for one.
 */
final class ApprovalDecisionEvent implements Event {

  /**
   * Builds the event.
   *
   * @param string $callId
   *   The id of the call the agent waits on.
   * @param string $decision
   *   Either approve or reject.
   */
  public function __construct(
    public readonly string $callId,
    public readonly string $decision,
  ) {}

}
