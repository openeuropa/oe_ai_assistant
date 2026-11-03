<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Carries what the drafter answered, before anything checks it.
 */
final class DraftedEvent implements Event {

  /**
   * Builds the event.
   *
   * @param string $answer
   *   The text of the answer, which should hold one JSON object.
   */
  public function __construct(
    public readonly string $answer,
  ) {}

}
