<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow;

use Drupal\ai_neuron\Attribute\NeuronWorkflow;
use Drupal\ai_neuron\Chat\History\ContextualMessageStoreInterface;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\ai_neuron\Workflow\NeuronWorkflowPluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaOutputNode;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaRetryNode;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ThreadAddress;
use Drupal\oe_ai_assistant\Neuron\Observability\RunListener;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Nodes\AgentStartNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;

/**
 * Writes the field values of one field group against its JSON schema.
 *
 * A workflow rather than an agent, because one run answers one schema and
 * holds no conversation: the task carries everything the model needs. It
 * also lets the graph say what it is, since Neuron's own structured output
 * derives a schema from a PHP class, and a drafting schema is composed per
 * bundle and template.
 */
#[NeuronWorkflow(
  id: 'field_group',
  label: new TranslatableMarkup('Field group drafter'),
  description: new TranslatableMarkup('Writes the values of one field group against its JSON schema.'),
)]
final class FieldGroupNeuronWorkflow extends NeuronWorkflowPluginBase {

  /**
   * The instructions every run starts from.
   */
  public const INSTRUCTIONS = <<<'PROMPT'
    You are a content generator. You will receive a JSON schema and
    instructions describing what content to produce. Generate a JSON
    object that conforms exactly to the given schema. Return ONLY
    valid JSON with no markdown fencing, no explanation, no commentary.

    Every field value is an array of items, each item an object with
    the property keys the schema lists, for example
    "field_teaser": [{"value": "Short teaser"}]. Use only the property
    names the schema defines, exactly as written, and match its
    array, object and property shape.

    For formatted text fields, produce clean HTML.
    Match the language and tone described in the instructions.
    PROMPT;

  /**
   * The drupal/ai operation type a group is drafted through.
   */
  private const OPERATION_TYPE = 'chat_with_structured_response';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ProviderFactoryInterface $providers,
    private readonly DraftingTurn $turn,
    private readonly ContextualMessageStoreInterface $store,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * No chat node and no tool node: one run answers one schema, and an answer
   * that does not match it routes to the retry.
   */
  protected function nodes(): array {
    $group = $this->turn->pendingGroup();

    return [
      new AgentStartNode(),
      new SchemaOutputNode($group['id'], $group['schema']),
      new SchemaRetryNode(),
      new AgentEndNode(),
    ];
  }

  /**
   * What the run can use: the provider, the conversation and the prompt.
   *
   * The nodes are an agent's, so they read an agent's resources.
   *
   * @param string $threadId
   *   The thread this run holds its conversation under.
   *
   * @return \NeuronAI\Agent\AgentResources
   *   The resources.
   */
  private function resources(string $threadId): AgentResources {
    $group = $this->turn->pendingGroup();

    return new AgentResources(
      $this->providers->create(self::OPERATION_TYPE, [$this->tag(), $group['id']]),
      // The group names the run, so its rows say which group they answered
      // for rather than naming the plugin every group shares.
      new ChatHistory($this->store->forAgent($group['id']), $threadId, ChatHistory::DEFAULT_CONTEXT_WINDOW),
      new SystemMessage($this->instructions()),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function startEvent(): Event {
    $group = $this->turn->pendingGroup();

    return new AgentStartEvent(
      [new UserMessage($group['task'])],
      new AgentRunOptions(outputClass: $group['id'], maxRetries: DraftingTurn::MAX_SCHEMA_RETRIES),
    );
  }

  /**
   * {@inheritdoc}
   *
   * One thread per group per chat turn, so a drafter is asked for one answer
   * and never replays the answer it gave for an earlier draft.
   */
  protected function runKey(): string {
    return ThreadAddress::nested(
      (string) $this->turn->session()->id(),
      $this->turn->token(),
      $this->turn->pendingGroup()['id'],
    );
  }

  /**
   * {@inheritdoc}
   *
   * Built here rather than by the base class, because the nodes are an
   * agent's: they read an AgentState and the resources an agent run carries,
   * and the base class builds a plain workflow with neither.
   */
  public function getNeuron(array $state = []): WorkflowInterface {
    $group = $this->turn->pendingGroup();
    $threadId = $this->runId($this->runKey());

    $agentState = new AgentState();
    foreach ($state as $key => $value) {
      $agentState->set($key, $value);
    }

    $workflow = Workflow::make($threadId, $agentState);
    $resources = $this->resources($threadId);

    $workflow
      ->addNodes($this->nodes())
      ->setStartEvent($this->startEvent())
      ->setResources(static fn (): AgentResources => $resources);

    $this->applyMiddleware($workflow);

    $workflow->subscribe(
      ObservabilityEvent::class,
      (new RunListener($group['id'], $this->turn->events()))->onEvent(...),
    );

    return $workflow;
  }

  /**
   * What the drafter is told to do, with the editorial context appended.
   */
  private function instructions(): string {
    $context = $this->turn->drafterPrompt();

    return $context === '' ? self::INSTRUCTIONS : self::INSTRUCTIONS . "\n\n" . $context . "\n";
  }

  /**
   * The tag every request of this drafter carries.
   */
  private function tag(): string {
    return 'oe_ai_assistant.drafter';
  }

}
