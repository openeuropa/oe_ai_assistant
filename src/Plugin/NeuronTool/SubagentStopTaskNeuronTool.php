<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Stops a background task of the session.
 */
#[NeuronTool(
  id: 'subagent_stop_task',
  description: 'Stops a background task of this session. The outcome is stopped (it had not'
  . ' started), stopping (it runs and stops shortly), finished (nothing to stop) or unknown.',
  label: new TranslatableMarkup('Stop task'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class SubagentStopTaskNeuronTool extends NeuronToolPluginBase {

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
      ToolProperty::make('task_id', PropertyType::STRING, 'The task to stop.', TRUE),
    ];
  }

  /**
   * Returns the outcome of the stop as JSON.
   */
  public function __invoke(string $task_id): string {
    return json_encode([
      'task_id' => $task_id,
      'outcome' => $this->ledger->stop($task_id, (int) $this->getContextValue('session')->id()),
    ]);
  }

}
