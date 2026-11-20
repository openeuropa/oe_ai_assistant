<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Tool;

/**
 * The Neuron tool of a plugin whose calls a subagent answers.
 */
final class SubagentTool extends Tool implements SubagentToolInterface {

  /**
   * Builds the tool.
   *
   * @param string $name
   *   The name the model calls.
   * @param string|null $description
   *   What the model is told the tool does.
   * @param array $properties
   *   The inputs the tool declares.
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition $definition
   *   The subagent that answers the calls.
   */
  public function __construct(
    string $name,
    ?string $description,
    array $properties,
    private readonly SubagentDefinition $definition,
  ) {
    $this->name = $name;
    $this->description = $description;
    $this->properties = $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function definition(): SubagentDefinition {
    return $this->definition;
  }

  /**
   * {@inheritdoc}
   */
  public function inputError(): ?string {
    // Neuron records the failed cast of setInputs() here, and only execute()
    // reads it. The node never executes this tool, so it asks for it instead.
    return $this->invalidInput;
  }

  /**
   * Refuses to run, because the subagent answers the calls of this tool.
   *
   * @throws \NeuronAI\Exceptions\ToolException
   *   Always.
   */
  public function __invoke(): string {
    throw new ToolException(sprintf('The "%s" tool is answered by its subagent, not by executing it.', $this->name));
  }

}
