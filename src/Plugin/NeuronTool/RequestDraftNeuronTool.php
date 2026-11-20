<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask;
use Drupal\oe_ai_assistant\Neuron\Subagent\WorkflowSubagent;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Workflow\Workflow;

/**
 * Asks the run for the next draft of the session.
 *
 * A draft takes minutes, and the editor watches the groups fill in while it
 * is written. So the call is answered by the drafter subagent, whose work is
 * the draft_content workflow.
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
final class RequestDraftNeuronTool extends SubagentToolPluginBase {

  /**
   * The id this tool is registered under.
   */
  public const string TOOL_ID = 'drafting_draft_content';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    SubagentTaskLedger $ledger,
    AccountProxyInterface $currentUser,
    private readonly NeuronWorkflowManagerInterface $workflows,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $ledger, $currentUser);
  }

  /**
   * {@inheritdoc}
   *
   * The drafters read the session's conversation, and conversationTurns()
   * only returns turns that have been closed. The turn that asks for a draft
   * is still open while this tool runs, so its message is missing from the
   * conversation. The agent repeats it in this property.
   */
  protected function properties(): array {
    return [
      ToolProperty::make(
        'brief',
        PropertyType::STRING,
        'What the editor asked for, in their own words. Quote the request'
        . ' that asked for this draft rather than summarising it.',
        TRUE,
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function definition(): SubagentDefinition {
    return new SubagentDefinition(
      'drafter',
      new WorkflowSubagent(fn (SubagentTask $task): Workflow => $this->workflows->createWorkflow('draft_content', [], [
        'session' => $this->getContextValue('session'),
        'call' => $task->callId,
        'brief' => $task->prompt,
        'task' => $task->taskId,
      ])),
      promptProperty: 'brief',
      background: TRUE,
    );
  }

}
