<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Starts a turn of the editor's conversation.
 *
 * The message and the version are one invocation's input rather than facts of
 * the session, so a caller hands them over on the event that starts the turn.
 */
final class ChatTurnEvent implements Event {

  /**
   * Builds the event.
   *
   * @param string $message
   *   What the editor sent.
   * @param int $version
   *   The draft this turn writes if the agent asks for one.
   */
  public function __construct(
    public readonly string $message,
    public readonly int $version,
  ) {}

}
