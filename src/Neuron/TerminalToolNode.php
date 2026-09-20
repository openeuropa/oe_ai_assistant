<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Workflow\Events\StopEvent;

/**
 * Tool node that ends the agent run once a terminal tool has been called.
 *
 * Any other tool call feeds its result back to the model as usual.
 */
final class TerminalToolNode extends ToolNode {

  /**
   * The state key holding the name of the terminal tool that ended the run.
   */
  public const STATE_KEY = 'terminal_tool';

  /**
   * TerminalToolNode constructor.
   *
   * @param string[] $terminalToolNames
   *   The tool names that end the run instead of looping back to the model.
   * @param int $maxRuns
   *   How many times one tool may run in a single agent run.
   * @param callable|null $errorHandler
   *   Turns a tool failure into a result for the model, or NULL to rethrow.
   */
  public function __construct(
    private readonly array $terminalToolNames,
    int $maxRuns = 10,
    ?callable $errorHandler = NULL,
  ) {
    parent::__construct($maxRuns, $errorHandler);
  }

  /**
   * {@inheritdoc}
   */
  public function __invoke(ToolCallEvent $event, AgentState $state): \Generator {
    $this->addToChatHistory($state, $event->toolCallMessage);
    $result = yield from $this->executeTools($event->toolCallMessage, $state);

    foreach ($event->toolCallMessage->getTools() as $tool) {
      if (in_array($tool->getName(), $this->terminalToolNames, TRUE)) {
        $state->set(self::STATE_KEY, $tool->getName());
        $this->addToChatHistory($state, $result);
        return new StopEvent();
      }
    }

    $event->inferenceEvent->setMessages($result);
    return $event->inferenceEvent;
  }

}
