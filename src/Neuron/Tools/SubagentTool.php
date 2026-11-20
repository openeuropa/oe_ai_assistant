<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentResult;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Tool;

/**
 * The Neuron tool of a plugin whose calls a subagent answers.
 */
final class SubagentTool extends Tool implements SubagentToolInterface {

  /**
   * The most background tasks a session runs at once.
   */
  public const int MAX_BACKGROUND = 3;

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
   * @param \Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger $ledger
   *   The ledger the tasks are recorded in.
   * @param int $sessionId
   *   The id of the session the tool serves.
   * @param int|null $uid
   *   The id of the user the tasks run for.
   * @param string $parentThread
   *   The thread of the agent that calls the tool.
   */
  public function __construct(
    string $name,
    ?string $description,
    array $properties,
    private readonly SubagentDefinition $definition,
    private readonly SubagentTaskLedger $ledger,
    private readonly int $sessionId,
    private readonly ?int $uid,
    private readonly string $parentThread,
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
   * {@inheritdoc}
   */
  public function open(SubagentTask $task): SubagentTask|string {
    // The cap is checked before the task is recorded, so a refused task
    // leaves no row behind.
    if ($task->background && $this->ledger->activeBackground($this->sessionId) >= self::MAX_BACKGROUND) {
      return sprintf('At most %d background tasks can run at once in this session. Wait for one to finish, or run this one now.', self::MAX_BACKGROUND);
    }

    if (!$task->continuation) {
      $this->ledger->record(
        $task->taskId,
        $this->sessionId,
        $this->name,
        $this->definition->name,
        $task->callId,
        $this->parentThread,
        $this->uid,
        $task->inputs,
        $task->background,
      );

      return $task;
    }

    $turn = $this->ledger->continueTask($task->taskId, $this->sessionId, $this->name, $task->inputs, $task->background);
    if (is_string($turn)) {
      return $turn;
    }

    return new SubagentTask($task->taskId, $task->callId, $task->prompt, $task->inputs, TRUE, $turn, $task->background);
  }

  /**
   * {@inheritdoc}
   */
  public function close(SubagentTask $task, SubagentResult $result): void {
    $this->ledger->finish($task->taskId, $result->status, $result->data);
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
