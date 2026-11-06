<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\ai_neuron\Tools\NeuronProxyTool;
use NeuronAI\Tools\DeferredToolInterface;

/**
 * A proxy tool whose result the run supplies, not the tool itself.
 *
 * Neuron reads the deferred flag off the tool in the agent's registry, and
 * ai_neuron registers a proxy there rather than the plugin. So the marker has
 * to sit on the proxy; a plugin that declares it alone is still executed.
 */
final class DeferredProxyTool extends NeuronProxyTool implements DeferredToolInterface {}
