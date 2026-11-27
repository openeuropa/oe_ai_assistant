<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\AmendContent\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Asks for one draft to be amended into its next minor version.
 */
final class AmendContentRequestEvent implements Event {}
