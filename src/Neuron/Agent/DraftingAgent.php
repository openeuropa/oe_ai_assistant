<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory;
use Drupal\oe_ai_assistant\Neuron\Tools\DraftCollector;
use Drupal\oe_ai_assistant\Neuron\Tools\DraftGroupTool;
use Drupal\oe_ai_assistant\Neuron\Tools\GetContentSchemaTool;
use Drupal\oe_ai_assistant\Neuron\Tools\GetDraftHistoryTool;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;

/**
 * Conversational agent that gathers requirements and drafts through tools.
 *
 * The model decides when to call the schema, history and group tools; the
 * group tool runs a drafter sub-agent per call. A tool failure is returned
 * to the model as an error payload, so the conversation continues.
 */
final class DraftingAgent extends Agent {

  /**
   * The instructions every run starts from.
   *
   * The tools describe themselves; this is the policy for using them.
   */
  public const INSTRUCTIONS = <<<'PROMPT'
    You are a content drafting assistant for a CMS editorial workflow.

    Workflow:
    - When the user asks to draft content, review the field groups
      provided below, or call get_content_schema for the latest ones.
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
    - Answer questions about earlier drafts with get_draft_history.
    - You can have normal conversations with the user at any point.
    PROMPT;

  /**
   * The tools, built once the conversation history is attached.
   *
   * @var \NeuronAI\Tools\ToolInterface[]|null
   */
  private ?array $declaredTools = NULL;

  /**
   * DraftingAgent constructor.
   *
   * @param \NeuronAI\Providers\AIProviderInterface $aiProvider
   *   The provider to call.
   * @param string $contextPrompt
   *   Content type context appended to the instructions.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The editorial session whose drafts the history tool lists.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface $draftHistory
   *   The draft history reader.
   * @param \Drupal\oe_ai_assistant\Neuron\Tools\DraftCollector $collector
   *   The collector of this turn's group results.
   * @param \Closure $drafter
   *   Drafts one group, called with the group id, the schema slice, the task
   *   prompt and the parent turn, and returning the decoded field values.
   */
  public function __construct(
    private readonly AIProviderInterface $aiProvider,
    private readonly string $contextPrompt,
    private readonly AiEditorialSessionInterface $session,
    private readonly DraftHistoryInterface $draftHistory,
    private readonly DraftCollector $collector,
    private readonly \Closure $drafter,
  ) {
    parent::__construct();
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
   */
  protected function tools(): array {
    return $this->declaredTools ??= [
      new GetContentSchemaTool($this->collector),
      new GetDraftHistoryTool($this->draftHistory, $this->session),
      new DraftGroupTool($this->collector, $this->conversation(), $this->drafter),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveToolErrorHandler(): ?callable {
    return static fn (\Throwable $e, ToolInterface $tool): string => json_encode(['error' => $e->getMessage()]);
  }

  /**
   * Returns the conversation history the run reads and writes.
   */
  private function conversation(): ConversationChatHistory {
    $history = $this->getChatHistory();
    assert($history instanceof ConversationChatHistory);
    return $history;
  }

}
