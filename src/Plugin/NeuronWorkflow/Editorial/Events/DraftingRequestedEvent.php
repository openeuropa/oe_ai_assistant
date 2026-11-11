<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Asks the run to write the draft the chat agent waits for.
 *
 * The event carries the identifiers of the calls that wait, not the calls
 * themselves. Every step of a run is serialized, and a tool call is a live
 * object of the agent's own run.
 */
final class DraftingRequestedEvent implements Event {

  /**
   * Builds the event.
   *
   * @param array $callIds
   *   The calls of the chat agent, each waiting for a draft of its own.
   */
  public function __construct(
    public readonly array $callIds,
  ) {}

}
