<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Subagent;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tools\ToolOutput;

/**
 * A subagent whose work is a Neuron agent plugin.
 */
final class AgentSubagent implements SubagentInterface {

  /**
   * The longest an input or a summary gets in an activity, in characters.
   */
  private const int SUMMARY_LENGTH = 200;

  /**
   * Builds the subagent.
   *
   * @param \Drupal\ai_neuron\Agent\NeuronAgentManagerInterface $agents
   *   The agent manager.
   * @param string $agentId
   *   The id of the agent plugin that does the work.
   * @param \Closure $contexts
   *   Builds the contexts of the agent for a task: fn (SubagentTask): array.
   * @param \Closure|null $report
   *   Builds the data of a completed result from the agent's final text and
   *   the log of its tool calls: fn (string $text, array $log): array.
   * @param string|null $askTool
   *   The tool the agent calls to put a question to the editor.
   */
  public function __construct(
    private readonly NeuronAgentManagerInterface $agents,
    private readonly string $agentId,
    private readonly \Closure $contexts,
    private readonly ?\Closure $report = NULL,
    private readonly ?string $askTool = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(SubagentTask $task): \Generator {
    $agent = $this->agents->createAgent($this->agentId, ($this->contexts)($task));

    // The message id names the turn, so a replay of the same turn finds the
    // message stored instead of appending it again.
    $message = new UserMessage($task->continuation ? 'The editor answered: ' . $task->prompt : $task->prompt);
    $message->setId($task->taskId . '.m' . $task->turn);

    $log = [];
    $open = [];
    $deltas = '';
    $question = NULL;
    $counter = 0;

    $chunks = $agent->stream($message);
    foreach ($chunks as $chunk) {
      if ($chunk instanceof TextChunk) {
        $deltas .= $chunk->content;
        yield new CustomStreamEvent('subagent-text', [
          'callId' => $task->callId,
          'taskId' => $task->taskId,
          'delta' => $chunk->content,
        ]);
      }
      elseif ($chunk instanceof ToolCallChunk) {
        $call = $chunk->tool;
        $id = 'subagent:' . $task->taskId . '.' . ($call->getCallId() ?? $call->getName() . '_' . ++$counter);
        $open[spl_object_id($call)] = $id;
        if ($call->getName() === $this->askTool && is_string($call->getInputs()['question'] ?? NULL)) {
          $question = $call->getInputs()['question'];
        }
        yield new ActivityStreamEvent($id, 'subagent', [
          'callId' => $task->callId,
          'taskId' => $task->taskId,
          'tool' => $call->getName(),
          'input' => $this->truncate(json_encode($call->getInputs()) ?: ''),
          'status' => 'running',
        ]);
      }
      elseif ($chunk instanceof ToolResultChunk) {
        $call = $chunk->tool;
        $id = $open[spl_object_id($call)] ?? 'subagent:' . $task->taskId . '.' . ($call->getCallId() ?? $call->getName() . '_' . ++$counter);
        $result = $call->hasResult() ? $call->getResult() : '';
        $error = $result instanceof ToolOutput && $result->isError();
        $text = (string) $result;
        $log[] = [
          'tool' => $call->getName(),
          'input' => $call->getInputs(),
          'result' => $text,
          'error' => $error,
        ];
        yield new ActivityStreamEvent($id, 'subagent', [
          'callId' => $task->callId,
          'taskId' => $task->taskId,
          'tool' => $call->getName(),
          'input' => $this->truncate(json_encode($call->getInputs()) ?: ''),
          'status' => $error ? 'failed' : 'done',
          'summary' => $this->truncate($text),
        ]);
      }
    }

    $state = $chunks->getReturn();
    assert($state instanceof AgentState);
    if ($state->isInterrupted()) {
      throw new WorkflowException(sprintf('The agent of task "%s" paused, and nothing can answer it.', $task->taskId));
    }

    $content = $state->getMessage()?->getContent();
    $text = is_string($content) && trim($content) !== '' ? trim($content) : trim($deltas);

    if ($question !== NULL) {
      return new SubagentResult(SubagentResult::NEEDS_INPUT, ['question' => $question] + ($text !== '' ? ['note' => $text] : []));
    }

    return new SubagentResult(
      SubagentResult::COMPLETED,
      $this->report === NULL ? ['report' => $text] : ($this->report)($text, $log),
    );
  }

  /**
   * Cuts a text to the length an activity carries.
   */
  private function truncate(string $text): string {
    return mb_strlen($text) > self::SUMMARY_LENGTH ? mb_substr($text, 0, self::SUMMARY_LENGTH - 1) . '…' : $text;
  }

}
