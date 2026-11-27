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
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Workflow\Workflow;

/**
 * Asks the run for the next draft of the session.
 *
 * A draft takes minutes, and the editor watches the groups fill in while it
 * is written. So the work is the draft_content workflow, which
 * WorkflowToolNode runs in place of executing this tool.
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
   *
   * The drafters can't see this conversation, so the brief is all they know
   * about what the editor wants. Keep it to the editor's intent. They read
   * the attached documents themselves with context_get_session_documents.
   * A brief that names a subject can send them after a document the editor
   * has since removed.
   */
  protected function properties(): array {
    return [
      ToolProperty::make(
        'brief',
        PropertyType::STRING,
        'What the editor asked for and how they want it, written for a'
        . ' drafter that cannot see this conversation: the angle they want,'
        . ' and anything they told you to include or leave out. Carry their'
        . ' intent and nothing else. Never name, describe or quote the'
        . ' attached material: the drafter reads whatever the session holds'
        . ' at the time it runs, which is not what you were told earlier.',
        TRUE,
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function workflow(ToolCall $call): Workflow {
    return $this->workflows->createWorkflow('draft_content', [], [
      'session' => $this->getContextValue('session'),
      'call' => (string) $call->getCallId(),
      'brief' => trim((string) ($call->getInput('brief') ?? '')),
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
