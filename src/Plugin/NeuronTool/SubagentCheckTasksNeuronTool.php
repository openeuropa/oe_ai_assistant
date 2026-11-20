<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentResult;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Lists the subagent tasks of the session, with the results that wait.
 */
#[NeuronTool(
  id: 'subagent_check_tasks',
  description: 'Lists the background and subagent tasks of this session with their status.'
  . ' A completed task whose result was not delivered yet comes with its result, and'
  . ' that result is then not delivered again. Pass task_id to look at one task.',
  label: new TranslatableMarkup('Check tasks'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class SubagentCheckTasksNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SubagentTaskLedger $ledger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      ToolProperty::make('task_id', PropertyType::STRING, 'The task to look at. Leave it out to list every task.', FALSE),
    ];
  }

  /**
   * Returns the tasks of the session as JSON.
   */
  public function __invoke(?string $task_id = NULL): string {
    $sessionId = (int) $this->getContextValue('session')->id();

    $tasks = [];
    foreach ($this->ledger->forSession($sessionId) as $row) {
      if ($task_id !== NULL && $task_id !== '' && $row['task_id'] !== $task_id) {
        continue;
      }
      $prompt = (string) ($row['input']['prompt'] ?? $row['input']['brief'] ?? '');
      $task = [
        'task_id' => $row['task_id'],
        'subagent' => $row['subagent'],
        'status' => $row['status'],
        'mode' => $row['mode'],
        'delivery_state' => $row['delivery_state'],
        'prompt' => mb_substr($prompt, 0, 200),
      ];
      // Reading the result here replaces its delivery.
      if ($row['status'] === SubagentResult::COMPLETED
        && $row['delivery_state'] === SubagentTaskLedger::WAITING
        && $this->ledger->consume($row['task_id'], $sessionId)) {
        $task['delivery_state'] = SubagentTaskLedger::CONSUMED;
        $task['result'] = $row['result'];
      }
      $tasks[] = $task;
    }

    return json_encode(['tasks' => $tasks], JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{"tasks":[]}';
  }

}
