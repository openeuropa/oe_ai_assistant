<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent;

use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SubagentToolNode;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Workflow\NodeInterface;

/**
 * The editor's agent, which hands the calls of some tools to subagents.
 *
 * Neuron's ToolNode executes a tool and waits for what it returns, so a tool
 * that takes minutes cannot report its progress. SubagentToolNode takes its
 * place here, and drives the subagent inside the run the chat endpoint is
 * streaming.
 */
final class EditorialAgent extends Agent {

  /**
   * {@inheritdoc}
   *
   * A node built here starts from its own defaults, so the call budget and
   * the error handler this agent was configured with are handed over again.
   *
   * @throws \LogicException
   *   When parallel tool calls are enabled, because SubagentToolNode runs the
   *   calls of a reply one after the other.
   */
  protected function nodes(): array {
    if ($this->parallelToolCalls) {
      throw new \LogicException('The editorial agent runs its tool calls one after the other; parallel tool calls are not supported.');
    }

    return array_map(
      fn (NodeInterface $node): NodeInterface => $node::class === ToolNode::class
        ? new SubagentToolNode($this->toolMaxRuns, $this->toolErrorHandler ?? $this->resolveToolErrorHandler())
        : $node,
      parent::nodes(),
    );
  }

}
