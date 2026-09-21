<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Chunks;

use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;

/**
 * Streams one observability event of an agent run to the editor.
 */
final class AgentEventChunk extends StreamChunk {

  /**
   * AgentEventChunk constructor.
   *
   * @param string $event
   *   The Neuron event name, such as inference-start.
   * @param string $agent
   *   The id of the agent the event belongs to.
   * @param string $summary
   *   One short line describing the event.
   * @param mixed $payload
   *   The event data as it serializes to JSON, or NULL for none.
   */
  public function __construct(
    public readonly string $event,
    public readonly string $agent,
    public readonly string $summary,
    public readonly mixed $payload = NULL,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return [
      'event' => $this->event,
      'agent' => $this->agent,
      'summary' => $this->summary,
      'payload' => $this->payload,
    ];
  }

}
