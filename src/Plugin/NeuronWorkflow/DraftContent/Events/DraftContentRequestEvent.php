<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Asks for a draft of every field group of the session's content type.
 */
final class DraftContentRequestEvent implements Event {}
