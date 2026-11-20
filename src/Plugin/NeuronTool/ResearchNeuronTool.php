<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Subagent\AgentSubagent;
use Drupal\oe_ai_assistant\Neuron\Subagent\ResearchReport;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentDefinition;
use Drupal\oe_ai_assistant\Neuron\Subagent\SubagentTask;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Researches the attached documents of the session through the researcher.
 */
#[NeuronTool(
  id: ResearchNeuronTool::TOOL_ID,
  description: 'Researches the documents attached to this session and returns a report whose'
  . ' claims cite [doc:<id> p.<n>]. Use it for details from the attached documents,'
  . ' especially long ones. When the result has status needs_input, relay its question'
  . ' to the editor verbatim, and send the answer as prompt with the same task_id.'
  . ' Pass task_id only to answer a question the researcher itself asked. If you asked'
  . ' the editor something yourself, start a new task with their answer in the prompt.',
  label: new TranslatableMarkup('Research documents'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class ResearchNeuronTool extends SubagentToolPluginBase {

  /**
   * The id this tool is registered under.
   */
  public const string TOOL_ID = 'research_documents';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    SubagentTaskLedger $ledger,
    AccountProxyInterface $currentUser,
    private readonly NeuronAgentManagerInterface $agents,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $ledger, $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      ToolProperty::make(
        'prompt',
        PropertyType::STRING,
        'The research question, with every detail the researcher needs, because it'
        . ' cannot see this conversation. To continue a task, the answer of the editor.',
        TRUE,
      ),
      ToolProperty::make(
        'task_id',
        PropertyType::STRING,
        'Only to answer a question the researcher itself asked, meaning its result had'
        . ' status needs_input: its task_id. The prompt is then the editor\'s answer.'
        . ' If you asked the editor yourself, start a new task instead.',
        FALSE,
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function definition(): SubagentDefinition {
    $session = $this->getContextValue('session');

    return new SubagentDefinition(
      'researcher',
      new AgentSubagent(
        $this->agents,
        'researcher',
        static fn (SubagentTask $task): array => ['session' => $session, 'task' => $task->taskId],
        ResearchReport::parse(...),
        'research_ask_editor',
      ),
      promptProperty: 'prompt',
      continuable: TRUE,
      background: TRUE,
    );
  }

}
