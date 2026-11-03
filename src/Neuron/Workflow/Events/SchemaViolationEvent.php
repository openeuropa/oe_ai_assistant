<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Says an answer does not match the schema of its group.
 */
final class SchemaViolationEvent implements Event {

  /**
   * Builds the event.
   *
   * @param array $violations
   *   One sentence per violation, as the validator reports them.
   */
  public function __construct(
    public readonly array $violations,
  ) {}

}
