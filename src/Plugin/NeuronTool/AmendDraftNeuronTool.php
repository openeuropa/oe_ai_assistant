<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Tools\WorkflowToolProxy;
use Drupal\oe_ai_assistant\Neuron\Tools\WorkflowToolInterface;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Workflow\Workflow;

/**
 * Asks the run to amend a stored draft and write the result as a new one.
 *
 * An amendment takes minutes, and the editor watches the groups fill in while
 * it is written. So the work is the amend_content workflow, which
 * WorkflowToolNode runs in place of executing this tool.
 */
#[NeuronTool(
  id: AmendDraftNeuronTool::TOOL_ID,
  description: 'Amends a stored draft and writes the result as a new draft version.'
  . ' Call it when the editor asks to change, revise or fix something already'
  . ' drafted. Name the version of the draft to amend. Name specific field'
  . ' group IDs only when the editor asked about specific fields; otherwise'
  . ' every group is amended. The result names the new draft ("Draft 2", and'
  . ' so on) and gives its version.',
  label: new TranslatableMarkup('Amend draft'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class AmendDraftNeuronTool extends NeuronToolPluginBase implements WorkflowToolInterface {

  /**
   * The id this tool is registered under.
   */
  public const string TOOL_ID = 'amend_draft';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NeuronWorkflowManagerInterface $workflows,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * WorkflowToolNode finds this tool in the registry as a Neuron object, not
   * as a plugin. So the object handed over is a WorkflowToolProxy, which
   * forwards workflow() back here.
   */
  public function getNeuron(): Tool {
    return WorkflowToolProxy::make(
      $this->getPluginId(),
      $this->description(),
      $this->properties(),
      $this,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      new ToolProperty('version', PropertyType::STRING, 'The version of the draft to amend, e.g. "1" or "1.1".', TRUE),
      new ArrayProperty('groups', 'The field group IDs to amend. Omit to amend every group.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function workflow(ToolCall $call): Workflow {
    return $this->workflows->createWorkflow('amend_content', [], [
      'session' => $this->getContextValue('session'),
      'call' => (string) $call->getCallId(),
      'version' => (string) ($call->getInput('version') ?? ''),
      'groups' => (array) ($call->getInput('groups') ?? []),
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \NeuronAI\Exceptions\ToolException
   *   Always. The workflow of this tool answers its calls, so nothing should
   *   execute it.
   */
  public function __invoke(): string {
    throw new ToolException(sprintf(
      'The "%s" tool is answered by its workflow, not by executing it.',
      self::TOOL_ID,
    ));
  }

}
