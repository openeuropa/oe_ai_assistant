<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Nodes;

use Drupal\oe_ai_assistant\Neuron\Agent\RouterAgent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\DraftingTurnWorkflow;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Events\DraftRequestedEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Runs the router agent on the user's message and streams its answer.
 *
 * The turn ends here when the router answers with text. When it calls a
 * terminal tool, the turn continues to the plan with the router's last turn
 * as the parent of everything the drafters record.
 */
final class RouteNode extends Node {

  public function __construct(
    private readonly RouterAgent $router,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function __invoke(StartEvent $event, WorkflowState $state): \Generator {
    $message = (string) $state->get(DraftingTurnWorkflow::MESSAGE, '');
    $state->set(DraftingTurnWorkflow::CONTEXT, $this->conversation($message));

    foreach ($this->router->stream(new UserMessage($message))->events() as $chunk) {
      yield $chunk;
    }

    if ($this->router->terminalTool() === NULL) {
      $state->set(DraftingTurnWorkflow::FINISH_REASON, 'stop');
      return new StopEvent();
    }

    $state->set(DraftingTurnWorkflow::FINISH_REASON, 'tool_calls');
    $state->set(DraftingTurnWorkflow::PARENT, $this->router->conversation()->lastAssistant());
    return new DraftRequestedEvent();
  }

  /**
   * Renders the conversation so far as role-prefixed lines for the drafters.
   */
  private function conversation(string $message): string {
    $lines = [];
    foreach ($this->router->conversation()->getMessages() as $item) {
      foreach ($item->getTextBlocks() as $block) {
        $lines[] = $item->getRole() . ': ' . $block->content;
      }
    }
    $lines[] = 'user: ' . $message;
    return implode("\n", $lines) . "\n";
  }

}
