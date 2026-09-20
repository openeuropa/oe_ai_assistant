<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use NeuronAI\Workflow\Events\Event;

/**
 * Routes the turn from the router to the plan once the model asked to draft.
 */
final class DraftRequestedEvent implements Event {
}
