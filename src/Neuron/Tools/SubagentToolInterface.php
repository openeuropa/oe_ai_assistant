<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition;
use NeuronAI\Tools\ToolInterface;

/**
 * A tool whose calls are answered by a subagent, not by executing the tool.
 *
 * SubagentToolNode finds the tool in the agent's registry, runs the subagent
 * in place of the call and streams what it reports.
 */
interface SubagentToolInterface extends ToolInterface {

  /**
   * Returns the subagent that answers the calls of this tool.
   *
   * @return \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition
   *   The definition.
   */
  public function definition(): SubagentDefinition;

  /**
   * Returns why the bound inputs were rejected.
   *
   * @return string|null
   *   The message for the model, or NULL when the inputs are valid.
   */
  public function inputError(): ?string;

}
