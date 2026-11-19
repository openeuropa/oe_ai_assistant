<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent;

use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\WorkflowToolNode;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Workflow\NodeInterface;

/**
 * The editor's agent, which runs the workflow of a tool that has one.
 *
 * Neuron's ToolNode executes a tool and waits for what it returns, so a tool
 * that takes minutes cannot report its progress. WorkflowToolNode takes its
 * place here, and drives the tool's workflow inside the run the chat endpoint
 * is streaming.
 */
final class EditorialAgent extends Agent {

  /**
   * {@inheritdoc}
   *
   * A node built here starts from its own defaults, so the call budget and
   * the error handler this agent was configured with are handed over again.
   */
  protected function nodes(): array {
    return array_map(
      fn (NodeInterface $node): NodeInterface => $node instanceof ToolNode
        ? new WorkflowToolNode($this->toolMaxRuns, $this->toolErrorHandler ?? $this->resolveToolErrorHandler())
        : $node,
      parent::nodes(),
    );
  }

}
