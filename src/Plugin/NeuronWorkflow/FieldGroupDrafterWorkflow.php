<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Attribute\NeuronWorkflow;
use Drupal\ai_neuron\Workflow\NeuronWorkflowPluginBase;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Workflow\Events\DraftRequestEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Nodes\DraftNode;
use Drupal\oe_ai_assistant\Neuron\Workflow\Nodes\RetryNode;
use Drupal\oe_ai_assistant\Neuron\Workflow\Nodes\ValidateNode;
use NeuronAI\Workflow\Events\Event;

/**
 * Drafts one field group, and asks again while the answer breaks its schema.
 *
 * The drafting tool runs this per group. The loop is a graph rather than a
 * PHP loop, so every attempt and every violation reaches the listeners of
 * the run.
 */
#[NeuronWorkflow(
  id: 'field_group_drafter',
  label: new TranslatableMarkup('Field group drafter'),
  description: new TranslatableMarkup('Asks the drafter for one field group and checks the answer against its schema.'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
    'group' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Field group'),
    ),
    'schema' => new ContextDefinition(
      data_type: 'any',
      label: new TranslatableMarkup('Group schema'),
    ),
    'task' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Task'),
    ),
  ],
)]
final class FieldGroupDrafterWorkflow extends NeuronWorkflowPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NeuronAgentManagerInterface $agents,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * The drafter's provider carries the schema as the editor's template wrote
   * it. The validator reads a closed copy, because a model that answers one
   * property of a group answers the schema as written.
   */
  protected function nodes(): array {
    $schema = (array) $this->getContextValue('schema');
    $group = (string) $this->getContextValue('group');

    return [
      new DraftNode($this->agents, $this->getContextValue('session'), $group, $schema),
      new ValidateNode($group, $schema + [
        'required' => array_keys($schema['properties'] ?? []),
        'additionalProperties' => FALSE,
      ]),
      new RetryNode($group),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The run starts from the same event the retry node returns, so one node
   * serves the first attempt and every correction.
   */
  protected function startEvent(): Event {
    return new DraftRequestEvent((string) $this->getContextValue('task'));
  }

}
