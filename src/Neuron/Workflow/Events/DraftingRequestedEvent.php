<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Events;

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
   *   The calls of the chat agent that wait for a draft.
   * @param int $version
   *   The draft to write.
   */
  public function __construct(
    public readonly array $callIds,
    public readonly int $version,
  ) {}

}
