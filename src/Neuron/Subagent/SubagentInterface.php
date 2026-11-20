<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Subagent;

/**
 * A child run that answers a tool call of the editorial agent.
 */
interface SubagentInterface {

  /**
   * Runs one task.
   *
   * The generator yields what the child reports while it works, already in
   * the form the editorial agent's stream can carry, and returns the result.
   *
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask $task
   *   The task to run.
   *
   * @return \Generator
   *   Yields stream events. Returns a SubagentResult.
   *
   * @throws \Throwable
   *   When the child cannot answer the task.
   */
  public function run(SubagentTask $task): \Generator;

}
