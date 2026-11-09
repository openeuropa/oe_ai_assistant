<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Starts a turn of the editor's conversation.
 *
 * A field on the session would hold the editor's message after the turn
 * ends, and the turn after it would read the message again. The message is
 * the input of one invocation, so the caller hands it over on this event.
 */
final class ChatTurnEvent implements Event {

  /**
   * Builds the event.
   *
   * @param string $message
   *   What the editor sent.
   */
  public function __construct(
    public readonly string $message,
  ) {}

}
