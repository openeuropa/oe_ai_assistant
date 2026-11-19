<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\ai_neuron\Tools\NeuronProxyTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Workflow;

/**
 * The Neuron tool of a plugin whose work is a workflow.
 *
 * A node looks a tool up in the registry of the run, which holds this object
 * and not the plugin behind it. So this is where WorkflowToolInterface has to
 * be declared, and workflow() is forwarded to the plugin.
 */
final class WorkflowToolProxy extends NeuronProxyTool implements WorkflowToolInterface {

  /**
   * The plugin that builds the workflow.
   *
   * NeuronProxyTool holds the plugin it runs in a private property, so this
   * class keeps a reference of its own.
   */
  private readonly WorkflowToolInterface $plugin;

  /**
   * Builds the tool.
   *
   * @param string $name
   *   The name the model calls.
   * @param string|null $description
   *   What the model is told the tool does.
   * @param array $properties
   *   The inputs the tool declares.
   * @param \Drupal\oe_ai_assistant\Neuron\Tools\WorkflowToolInterface $plugin
   *   The plugin that builds the workflow, and that the tool runs.
   */
  public function __construct(
    string $name,
    ?string $description,
    array $properties,
    WorkflowToolInterface $plugin,
  ) {
    parent::__construct($name, $description, $properties, $plugin);
    $this->plugin = $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function workflow(ToolCall $call): Workflow {
    return $this->plugin->workflow($call);
  }

}
