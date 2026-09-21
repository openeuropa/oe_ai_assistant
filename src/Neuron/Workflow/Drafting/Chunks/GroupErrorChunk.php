<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Chunks;

use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;

/**
 * Streams the failure of one group; the other groups still run.
 */
final class GroupErrorChunk extends StreamChunk {

  public function __construct(
    public readonly string $stepId,
    public readonly string $message,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['stepId' => $this->stepId, 'message' => $this->message];
  }

}
