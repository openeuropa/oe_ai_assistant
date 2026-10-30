<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Asks the drafter for one answer.
 *
 * The run starts from this event, and the retry node returns it again with
 * the violations to correct, so one node serves both.
 */
final class DraftRequestEvent implements Event {

  /**
   * Builds the event.
   *
   * @param string $message
   *   What to tell the drafter: the task, or the correction to apply.
   */
  public function __construct(
    public readonly string $message,
  ) {}

}
