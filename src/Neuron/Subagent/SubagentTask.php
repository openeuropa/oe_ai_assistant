<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Subagent;

/**
 * One piece of work handed to a subagent.
 */
final class SubagentTask {

  /**
   * Builds the task.
   *
   * @param string $taskId
   *   The id of the task, which also names the child's run.
   * @param string $callId
   *   The id of the tool call the task answers.
   * @param string $prompt
   *   What the editorial agent asked the subagent to do.
   * @param array $inputs
   *   Every input of the call, as the model sent them.
   */
  public function __construct(
    public readonly string $taskId,
    public readonly string $callId,
    public readonly string $prompt,
    public readonly array $inputs = [],
  ) {}

}
