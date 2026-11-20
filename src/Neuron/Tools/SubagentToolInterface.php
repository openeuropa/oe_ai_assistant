<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentResult;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask;
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

  /**
   * Opens a task in the ledger before its subagent runs.
   *
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask $task
   *   The task. A new one is recorded, and a continuation is claimed.
   *
   * @return \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask|string
   *   The task to run, or the message for the model when it cannot run.
   */
  public function open(SubagentTask $task): SubagentTask|string;

  /**
   * Settles a task in the ledger once its subagent stopped.
   *
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask $task
   *   The task.
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentResult $result
   *   What the subagent reported.
   */
  public function close(SubagentTask $task, SubagentResult $result): void;

}
