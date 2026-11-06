<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Says the draft is written, so the turn that asked for it can go on.
 *
 * The results the chat agent reads are on the state of the run, because a
 * draft holds every field of the content type and an event is serialized at
 * every step.
 */
final class DraftStoredEvent implements Event {}
