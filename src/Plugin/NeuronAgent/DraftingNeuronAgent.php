<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ThreadAddress;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
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
    - When the user asks to save a draft, call save_draft with its version
      number. The editor confirms the save before it happens, so say what
      the result reports rather than promising that it is saved.
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
    'get_editorial_context',
    'read_document',
    'draft_group',
    'revise_draft',
    'save_draft',
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ProviderFactoryInterface $providers,
    private readonly NeuronToolManagerInterface $toolManager,
    private readonly DraftingTurn $turn,
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
   * The base class sets the provider, the instructions, the tools and the
   * middleware, and leaves a run yielding Neuron's own chunks. The app reads
   * the Vercel protocol, so the run is asked for that.
   */
  public function getNeuron(?string $threadKey = NULL): AgentInterface {
    $agent = parent::getNeuron($threadKey);
    assert($agent instanceof Agent);
    $agent->setStreamAdapter(static fn (): VercelAIAdapter => new VercelAIAdapter());

    return $agent;
  }

  /**
   * {@inheritdoc}
   *
   * A tool answers the model in JSON, so a failure is reported the same way
   * rather than as the sentence the base class returns.
   */
  protected function toolErrorHandler(): ?callable {
    return static fn (\Throwable $exception, ToolCall $call): string => json_encode(['error' => $exception->getMessage()]);
  }

}
