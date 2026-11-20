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
   * @param bool $continuation
   *   Whether the task continues one that waited for the editor.
   * @param int $turn
   *   How many times the task was continued, which is 0 for a new task.
   */
  public function __construct(
    public readonly string $taskId,
    public readonly string $callId,
    public readonly string $prompt,
    public readonly array $inputs = [],
    public readonly bool $continuation = FALSE,
    public readonly int $turn = 0,
  ) {}

}
