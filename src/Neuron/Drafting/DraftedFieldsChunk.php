<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;

/**
 * Streams the consolidated field values once every group has run.
 */
final class DraftedFieldsChunk extends StreamChunk {

  public function __construct(public readonly array $fields) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['fields' => $this->fields];
  }

}
