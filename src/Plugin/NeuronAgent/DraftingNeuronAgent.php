<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationMessageStore;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ThreadAddress;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters\UiMessageStreamAdapter;
use Drupal\oe_ai_assistant\Neuron\Observability\RunListener;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Tools\ToolCall;

/**
 * The agent that talks to the editor and drafts through tools.
 */
#[NeuronAgent(
  id: 'drafting',
  label: new TranslatableMarkup('Drafting'),
  description: new TranslatableMarkup('Gathers requirements in conversation and drafts content per field group.'),
  operationType: 'chat_with_tools',
)]
final class DraftingNeuronAgent extends NeuronAgentPluginBase {

  /**
   * The instructions every run starts from.
   *
   * The tools describe themselves; this is the policy for using them.
   */
  public const INSTRUCTIONS = <<<'PROMPT'
    You are a content drafting assistant for a CMS editorial workflow.

    Workflow:
    - When the user asks to draft content, review the field groups
      provided below.
    - For each group, determine whether you have enough information
      from the conversation to generate meaningful content. If ANY
      field group lacks context, ask the user about it specifically.
    - Do NOT call draft_group until you have addressed every field
      group. Ask the user about each group you are unsure about.
    - The user may tell you to skip certain fields, use your best
      judgment, or just go ahead. In that case, proceed with
      draft_group using whatever context you have.
    - Only call draft_group when either:
      (a) you have gathered specific information for all field
          groups, or
      (b) the user has explicitly told you to proceed without it.
    - Call draft_group once for every group, all in the same turn,
      starting with main_fields. If a result still lists pending
      groups, call draft_group for each of them before answering.
    - Once the draft is versioned, tell the user which draft is ready
      and that they can review it on the right. Do not repeat the
      field values.
    - When the user asks to change something in a draft that already
      exists, call revise_draft rather than drafting again. Name the
      groups only when the change is limited to particular fields;
      leave them out when it applies to the whole draft. Revise the
      most recent draft unless the user points at another one.
    - The field groups below are the ones a new draft follows. A stored
      draft keeps the groups it was written with, which may differ;
      get_draft_history lists them per draft.
    - Answer questions about earlier drafts with get_draft_history.
    - Answer questions about what the editor changed in the session, and
      when or how often, with get_session_history. It lists the tone and
      template the session started with, every later change to either, and
      every draft saved.
    - Answer questions about the session itself, what it is about, what
      material is attached, what a document covers, by calling
      get_editorial_context first. Read one document in full with
      read_document only when its summary does not answer the question.
      Never guess what a document contains.
    - You can have normal conversations with the user at any point.
    PROMPT;

  /**
   * The tools the agent publishes, by plugin id.
   */
  private const TOOLS = [
    'get_draft_history',
    'get_session_history',
    'get_editorial_context',
    'read_document',
    'draft_group',
    'revise_draft',
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ProviderFactoryInterface $providers,
    private readonly NeuronToolManagerInterface $toolManager,
    private readonly DraftingTurn $turn,
    private readonly ConversationMessageStore $store,
    private readonly MessageRecorderInterface $recorder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $providers);
  }

  /**
   * {@inheritdoc}
   */
  protected function instructions(): string {
    return self::INSTRUCTIONS . "\n\n" . $this->turn->agentPrompt() . "\n";
  }

  /**
   * {@inheritdoc}
   */
  protected function tools(): array {
    return $this->toolManager->createTools(self::TOOLS);
  }

  /**
   * {@inheritdoc}
   *
   * The editorial session is the conversation, so a turn picks up where
   * the last one left off.
   */
  protected function threadKey(): string {
    return ThreadAddress::key((string) $this->turn->session()->id());
  }

  /**
   * {@inheritdoc}
   *
   * Built here rather than by the base class, because a tool failure has to
   * come back to the model as an answer and the conversation reads its own
   * history.
   */
  public function getNeuron(?string $threadKey = NULL): AgentInterface {
    // The base class sets the provider, the instructions, the tools and the
    // middleware; what follows is what it does not reach.
    $agent = parent::getNeuron($threadKey);
    assert($agent instanceof Agent);
    $threadId = (string) $agent->getThreadId();

    $agent
      // A tool that throws answers the model with the failure, so the
      // conversation carries on instead of the run ending.
      ->toolErrorHandler(static fn (\Throwable $e, ToolCall $call): string => json_encode(['error' => $e->getMessage()]))
      // The app decodes its own dialect of the UI message stream, so the
      // run yields those protocol events rather than Neuron's own.
      ->setStreamAdapter(static fn (): UiMessageStreamAdapter => new UiMessageStreamAdapter());

    $agent->subscribe(ObservabilityEvent::class, (new RunListener(
      $this->recorder,
      $this->turn->session(),
      $this->getPluginId(),
      $this->turn->events(),
      $this->store,
      $threadId,
    ))->onEvent(...));

    return $agent;
  }

}
