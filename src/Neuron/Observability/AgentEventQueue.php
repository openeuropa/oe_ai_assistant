<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Observability;

use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Chunks\AgentEventChunk;

/**
 * Holds agent events until the stream loop can interleave them with chunks.
 *
 * Observers run between two yields of the agent generator, so draining the
 * queue before each chunk keeps the events in the order they happened.
 */
final class AgentEventQueue {

  /**
   * The queued chunks, oldest first.
   *
   * @var \Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Chunks\AgentEventChunk[]
   */
  private array $chunks = [];

  /**
   * Queues one event.
   */
  public function push(AgentEventChunk $chunk): void {
    $this->chunks[] = $chunk;
  }

  /**
   * Returns and forgets every queued event.
   *
   * @return \Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Chunks\AgentEventChunk[]
   *   The queued chunks, oldest first.
   */
  public function drain(): array {
    $chunks = $this->chunks;
    $this->chunks = [];
    return $chunks;
  }

}
