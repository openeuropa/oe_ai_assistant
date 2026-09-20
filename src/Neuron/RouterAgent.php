<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Node;

/**
 * Conversational agent that gathers requirements and signals when to draft.
 *
 * The run ends either with a text answer or with a call to one of the
 * terminal tools, which the caller reads through terminalTool().
 */
final class RouterAgent extends Agent {

  /**
   * RouterAgent constructor.
   *
   * @param \NeuronAI\Providers\AIProviderInterface $aiProvider
   *   The provider to call.
   * @param string $systemPrompt
   *   The complete instructions.
   * @param \NeuronAI\Tools\ToolInterface[] $tools
   *   The tools offered to the model.
   * @param string[] $terminalToolNames
   *   The tool names that end the run instead of looping back to the model.
   */
  public function __construct(
    private readonly AIProviderInterface $aiProvider,
    private readonly string $systemPrompt,
    array $tools,
    private readonly array $terminalToolNames,
  ) {
    parent::__construct();
    $this->addTool($tools);
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
    return $this->systemPrompt;
  }

  /**
   * {@inheritdoc}
   *
   * Swaps the default tool node for one that stops on a terminal tool.
   */
  protected function compose(array|Node $nodes): void {
    if ($this->eventNodeMap !== []) {
      return;
    }
    $this->addNodes([
      ...(is_array($nodes) ? $nodes : [$nodes]),
      new TerminalToolNode($this->terminalToolNames, $this->toolMaxRuns, $this->resolveToolErrorHandler()),
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
