<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Nodes;

use Drupal\oe_ai_assistant\Neuron\Agent\Events\SchemaViolationEvent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Workflow\Node;

/**
 * Asks the model again for an answer that failed schema validation.
 *
 * The violations travel back as a user message, so the model is told what
 * was wrong with its own answer rather than being asked the same question
 * twice. The run fails once the attempts are spent.
 */
final class SchemaRetryNode extends Node {

  /**
   * The state key counting the corrections asked for in this run.
   */
  private const ATTEMPTS_KEY = 'schema_retries';

  /**
   * SchemaRetryNode constructor.
   *
   * @param int $maxRetries
   *   How many corrected answers to ask for before giving up.
   */
  public function __construct(
    private readonly int $maxRetries = 1,
  ) {}

  /**
   * Feeds the violations back to the model, or gives up.
   *
   * @throws \NeuronAI\Exceptions\AgentException
   *   When no answer matched the schema within the allowed retries.
   */
  public function __invoke(SchemaViolationEvent $event, AgentState $state): AIInferenceEvent {
    $attempts = (int) $state->get(self::ATTEMPTS_KEY, 0) + 1;
    $state->set(self::ATTEMPTS_KEY, $attempts);

    if ($attempts > $this->maxRetries) {
      throw new AgentException(sprintf(
        'The "%s" answer does not match its schema: %s',
        $event->schema,
        implode('; ', $event->violations),
      ));
    }

    $event->inferenceEvent->setMessages(new UserMessage(
      "Your previous answer does not match the schema:\n- "
      . implode("\n- ", $event->violations)
      . "\n\nAnswer again with one JSON object that matches the schema exactly."
    ));
    return $event->inferenceEvent;
  }

}
