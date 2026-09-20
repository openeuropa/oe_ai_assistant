<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;

/**
 * Streams the versioned draft result stored on the draft_content call.
 */
final class DraftResultChunk extends StreamChunk {

  /**
   * DraftResultChunk constructor.
   *
   * @param array $result
   *   The result shaped {version, context, fields}.
   */
  public function __construct(public readonly array $result) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['result' => $this->result];
  }

}
