<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Events;

use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Workflow\Events\Event;

/**
 * Routes an answer that does not match its schema back for another attempt.
 */
final class SchemaViolationEvent implements Event {

  /**
   * SchemaViolationEvent constructor.
   *
   * @param string $schema
   *   The name of the schema the answer was validated against.
   * @param string[] $violations
   *   The validation errors, one line each, as the validator reports them.
   * @param \NeuronAI\Agent\Events\AIInferenceEvent $inferenceEvent
   *   The inference this answer came from, carried so the retry can run it
   *   again with the violations appended.
   */
  public function __construct(
    public readonly string $schema,
    public readonly array $violations,
    public readonly AIInferenceEvent $inferenceEvent,
  ) {}

}
