<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Nodes;

use Drupal\oe_ai_assistant\Neuron\Workflow\Events\DraftRequestEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Events\SchemaViolationEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Asks the drafter again with the violations, or gives up.
 */
final class RetryNode extends Node {

  /**
   * The state key counting the corrections asked for in this run.
   */
  public const string ATTEMPTS_KEY = 'schema_retries';

  /**
   * Builds the node.
   *
   * @param string $group
   *   The field group the run drafts.
   * @param int $maxRetries
   *   How many corrected answers to ask for before giving up.
   */
  public function __construct(
    private readonly string $group,
    private readonly int $maxRetries = 5,
  ) {}

  /**
   * Feeds the violations back to the drafter.
   *
   * @throws \RuntimeException
   *   When no answer matched the schema within the allowed retries.
   */
  public function __invoke(SchemaViolationEvent $event, WorkflowState $state): DraftRequestEvent {
    $attempts = (int) $state->get(self::ATTEMPTS_KEY, 0) + 1;
    $state->set(self::ATTEMPTS_KEY, $attempts);

    if ($attempts > $this->maxRetries) {
      throw new \RuntimeException(sprintf(
        'The "%s" answer does not match its schema: %s',
        $this->group,
        implode('; ', $event->violations),
      ));
    }

    // The drafter's conversation already holds the answer to correct, so the
    // next turn carries the violations and nothing else.
    return new DraftRequestEvent(
      "Your previous answer does not match the schema:\n- "
      . implode("\n- ", $event->violations)
      . "\n\nAnswer again with one JSON object that matches the schema exactly."
    );
  }

}
