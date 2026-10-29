<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Routes an answer that does not match its schema back for another attempt.
 *
 * The inference the answer came from is not carried here: the request lives
 * on the agent state, so the retry node adds its correction to that and
 * asks for the inference again.
 */
final class SchemaViolationEvent implements Event {

  public function __construct(
    public readonly string $schema,
    public readonly array $violations,
  ) {}

}
