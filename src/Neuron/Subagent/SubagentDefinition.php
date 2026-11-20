<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Subagent;

/**
 * Declares a subagent and how its tool hands work to it.
 */
final class SubagentDefinition {

  /**
   * Builds the definition.
   *
   * @param string $name
   *   The name the subagent reports under, in results and stream events.
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentInterface $subagent
   *   The child that runs the tasks.
   * @param string $promptProperty
   *   The tool input that carries what the subagent is asked to do.
   */
  public function __construct(
    public readonly string $name,
    public readonly SubagentInterface $subagent,
    public readonly string $promptProperty = 'prompt',
  ) {}

  /**
   * Checks a task before the child runs it.
   *
   * @param \Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask $task
   *   The task the model asked for.
   *
   * @return string|null
   *   Why the task cannot run, for the model to read, or NULL when it can.
   */
  public function validate(SubagentTask $task): ?string {
    // The gateway does not enforce the tool's schema, so a call can arrive
    // with the prompt present but empty.
    if (trim($task->prompt) === '') {
      return sprintf('Parameter "%s" must not be empty.', $this->promptProperty);
    }

    return NULL;
  }

}
