<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\oe_ai_assistant\Neuron\Tools\DraftContentTool;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Node;

/**
 * Conversational agent that gathers requirements and signals when to draft.
 *
 * The run ends either with a text answer or with a call to the draft
 * signal tool, which the caller reads through terminalTool().
 */
final class RouterAgent extends Agent {

  /**
   * The instructions every run starts from.
   */
  public const INSTRUCTIONS = <<<'PROMPT'
    You are a content drafting assistant for a CMS editorial workflow.

    You have three tools available:

    1. get_content_schema: Call this to get the latest field groups of
       the content type. It returns groups of fields with their types
       and descriptions. The field groups are also provided below for
       convenience.

    2. draft_content: Call this ONLY when you have gathered ALL the
       information needed to fill every field in the schema. This
       triggers the content generation process.

    3. get_draft_history: Call this when the user asks about earlier
       drafts or which tone, template or documents produced a draft.
       It returns one entry per generated draft ("Draft 1", "Draft 2",
       and so on) with the context snapshot captured at generation
       time. Refer to drafts by these names; the user sees the same
       names.

    Workflow:
    - When the user asks to draft content, review the field groups
      (provided below or via get_content_schema).
    - For each group, determine whether you have enough information
      from the conversation to generate meaningful content. If ANY
      field group lacks context, ask the user about it specifically.
    - Do NOT call draft_content until you have addressed every
      field group. Ask the user about each group you are unsure
      about.
    - The user may tell you to skip certain fields, use your best
      judgment, or just go ahead. In that case, proceed with
      draft_content using whatever context you have.
    - Only call draft_content when either:
      (a) you have gathered specific information for all field
          groups, or
      (b) the user has explicitly told you to proceed without it.
    - You can have normal conversations with the user at any point.
    PROMPT;

  /**
   * RouterAgent constructor.
   *
   * @param \NeuronAI\Providers\AIProviderInterface $aiProvider
   *   The provider to call.
   * @param \NeuronAI\Tools\ToolInterface[] $tools
   *   The information tools offered to the model, next to the draft signal.
   * @param string $contextPrompt
   *   Content type context appended to the instructions.
   */
  public function __construct(
    private readonly AIProviderInterface $aiProvider,
    array $tools,
    private readonly string $contextPrompt,
  ) {
    parent::__construct();
    $this->addTool([...$tools, new DraftContentTool()]);
  }

  /**
   * {@inheritdoc}
   */
  protected function provider(): AIProviderInterface {
    return $this->aiProvider;
  }

  /**
   * {@inheritdoc}
   */
  protected function instructions(): string {
    return self::INSTRUCTIONS . "\n\n" . $this->contextPrompt . "\n";
  }

  /**
   * {@inheritdoc}
   *
   * Swaps the default tool node for one that stops on the draft signal.
   */
  protected function compose(array|Node $nodes): void {
    if ($this->eventNodeMap !== []) {
      return;
    }
    $this->addNodes([
      ...(is_array($nodes) ? $nodes : [$nodes]),
      new TerminalToolNode([DraftContentTool::NAME], $this->toolMaxRuns, $this->resolveToolErrorHandler()),
    ]);
  }

  /**
   * Returns the conversation history the run reads and writes.
   */
  public function conversation(): ConversationChatHistory {
    $history = $this->getChatHistory();
    assert($history instanceof ConversationChatHistory);
    return $history;
  }

  /**
   * Returns the terminal tool that ended the run, or NULL for a text answer.
   */
  public function terminalTool(): ?string {
    return $this->resolveState()->get(TerminalToolNode::STATE_KEY);
  }

}
