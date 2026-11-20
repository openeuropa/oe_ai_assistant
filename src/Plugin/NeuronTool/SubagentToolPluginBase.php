<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition;
use Drupal\oe_ai_assistant\Neuron\Tools\SubagentTool;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Tool;

/**
 * Base class of a tool plugin whose calls a subagent answers.
 */
abstract class SubagentToolPluginBase extends NeuronToolPluginBase {

  /**
   * Returns the subagent that answers the calls of this tool.
   *
   * @return \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition
   *   The definition.
   */
  abstract protected function definition(): SubagentDefinition;

  /**
   * {@inheritdoc}
   *
   * The node finds the tool in the registry as a Neuron object, not as this
   * plugin. So the object handed over carries the definition itself.
   */
  public function getNeuron(): Tool {
    return new SubagentTool($this->getPluginId(), $this->description(), $this->properties(), $this->definition());
  }

  /**
   * Refuses to run, because the subagent answers the calls of this tool.
   *
   * @throws \NeuronAI\Exceptions\ToolException
   *   Always.
   */
  public function __invoke(): string {
    throw new ToolException(sprintf('The "%s" tool is answered by its subagent, not by executing it.', $this->getPluginId()));
  }

}
