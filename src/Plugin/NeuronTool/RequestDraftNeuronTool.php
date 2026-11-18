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
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Workflow;

/**
 * Asks the run for the next draft of the session.
 *
 * A tool runs between two yields of the agent that called it, so it cannot
 * report the drafting of a group back to the editor. The work is a workflow
 * instead, which WorkflowToolNode runs in place of executing this tool.
 */
#[NeuronTool(
  id: RequestDraftNeuronTool::TOOL_ID,
  description: 'Drafts the content of every field group and stores it as the next draft.'
  . ' Call it once, when the editor is ready for a draft. The result names the'
  . ' draft ("Draft 1", "Draft 2", and so on) and gives its version;'
  . ' drafting_get_history lists the drafts of this session.',
  label: new TranslatableMarkup('Draft content'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class RequestDraftNeuronTool extends NeuronToolPluginBase implements WorkflowToolInterface {

  /**
   * The id this tool is registered under.
   */
  public const string TOOL_ID = 'drafting_draft_content';

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
   * A node reads the tools of a run off the registry, which holds the Neuron
   * object rather than this plugin. So the object it is given says that its
   * work is a workflow, and asks this plugin for it.
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
  public function workflow(ToolCall $call): Workflow {
    return $this->workflows->createWorkflow('draft_content', [], [
      'session' => $this->getContextValue('session'),
      'call' => (string) $call->getCallId(),
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \NeuronAI\Exceptions\ToolException
   *   Always. The workflow of this tool answers its calls, so a node that
   *   executes it instead has not read workflow().
   */
  public function __invoke(): string {
    throw new ToolException(sprintf(
      'The "%s" tool is answered by its workflow, not by executing it.',
      self::TOOL_ID,
    ));
  }

}
