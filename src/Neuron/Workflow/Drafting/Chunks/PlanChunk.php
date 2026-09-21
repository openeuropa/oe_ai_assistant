<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Chunks;

use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;

/**
 * Streams the drafting plan, one entry per group with its status.
 */
final class PlanChunk extends StreamChunk {

  /**
   * PlanChunk constructor.
   *
   * @param array $plan
   *   Entries shaped {stepId, label, status} with status one of pending,
   *   in_progress, done or error.
   */
  public function __construct(public readonly array $plan) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['plan' => $this->plan];
  }

}
