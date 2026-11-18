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
 * A tool of this module does its work in a workflow, so that the work can
 * report progress while it runs. Neuron's tool node executes a tool and waits
 * for what it returns, so nodes() swaps it for one that runs that workflow
 * instead. One run then serves a whole turn, and it is the run the chat
 * endpoint is streaming.
 */
final class EditorialAgent extends Agent {

  /**
   * {@inheritdoc}
   *
   * The node takes the call budget and the error handler this agent was
   * configured with, because the node it stands in for is built with both.
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
