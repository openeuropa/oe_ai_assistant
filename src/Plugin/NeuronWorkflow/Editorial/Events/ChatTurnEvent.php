<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Starts a turn of the editor's conversation.
 *
 * A field on the session would still hold the message after the turn ends.
 * The next turn would then answer the message of the turn before it. The
 * message is the input of one invocation, so the caller passes it on this
 * event.
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
