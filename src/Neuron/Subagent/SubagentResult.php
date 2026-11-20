<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Subagent;

/**
 * What a subagent reports back for one task.
 */
final class SubagentResult {

  /**
   * The task is done, and the data holds its answer.
   */
  public const string COMPLETED = 'completed';

  /**
   * The task stopped to ask the editor something.
   */
  public const string NEEDS_INPUT = 'needs_input';

  /**
   * The task failed, and the data says why.
   */
  public const string FAILED = 'failed';

  /**
   * Builds the result.
   *
   * @param string $status
   *   One of the status constants of this class.
   * @param array $data
   *   What the model reads besides the status.
   */
  public function __construct(
    public readonly string $status,
    public readonly array $data = [],
  ) {}

  /**
   * Builds the result of a workflow that left its answer in its state.
   *
   * @param array $result
   *   The answer the workflow stored. One with an "error" key is a failure.
   *
   * @return self
   *   The result.
   */
  public static function fromWorkflow(array $result): self {
    return new self(isset($result['error']) ? self::FAILED : self::COMPLETED, $result);
  }

  /**
   * Returns what the model reads as the result of the call.
   *
   * @param string $taskId
   *   The id of the task.
   * @param string $subagent
   *   The name of the subagent that ran it.
   *
   * @return array
   *   The result, led by the task id, the subagent and the status.
   */
  public function toArray(string $taskId, string $subagent): array {
    return [
      'task_id' => $taskId,
      'subagent' => $subagent,
      'status' => $this->status,
    ] + $this->data;
  }

}
