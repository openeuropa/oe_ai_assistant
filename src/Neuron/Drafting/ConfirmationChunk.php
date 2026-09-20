<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;

/**
 * Streams the confirmation text that closes a drafting turn.
 */
final class ConfirmationChunk extends StreamChunk {

  public function __construct(public readonly string $text) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['text' => $this->text];
  }

}
