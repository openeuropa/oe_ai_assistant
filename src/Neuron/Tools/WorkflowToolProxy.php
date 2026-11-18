<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\ai_neuron\Tools\NeuronProxyTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Workflow;

/**
 * The Neuron tool of a plugin whose work is a workflow.
 *
 * A node reads the tools of a run off the registry, which holds the Neuron
 * object and not the plugin behind it. So the object says that its work is a
 * workflow, and asks the plugin for the one that answers a call.
 */
final class WorkflowToolProxy extends NeuronProxyTool implements WorkflowToolInterface {

  /**
   * The plugin that builds the workflow.
   *
   * The plugin the parent runs is its own, and private, so the one this asks
   * for a workflow is kept here.
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
   *   The arguments the model may pass.
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
