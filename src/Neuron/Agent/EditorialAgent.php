<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\DeferredCallsNode;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\AwaitToolResultsNode;
use NeuronAI\Workflow\NodeInterface;

/**
 * The editor's agent, which answers its own deferred tool calls.
 *
 * This module's long-running tools are deferred, and Neuron's
 * AwaitToolResultsNode suspends the run for them and waits for a caller to
 * submit the results. Nobody submits them here. So nodes() swaps that node
 * for DeferredCallsNode, which runs the work itself and sends the turn back
 * to the model with what it produced. One run then serves a whole turn, and
 * it is the run the chat endpoint is streaming.
 */
final class EditorialAgent extends Agent {

  /**
   * Builds the workflow that answers a deferred call.
   */
  private NeuronWorkflowManagerInterface $workflows;

  /**
   * Records a workflow that could not answer its call.
   */
  private LoggerChannelInterface $logger;

  /**
   * The session this agent serves.
   */
  private ContentCreationSessionInterface $session;

  /**
   * Gives DeferredCallsNode what it needs to start a workflow.
   *
   * NeuronAgentPluginBase builds an agent through a factory that takes
   * nothing but a thread id, so the plugin hands the rest over here, once the
   * agent exists.
   *
   * @param \Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface $workflows
   *   Builds the workflow that answers a deferred call.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Records a workflow that could not answer its call.
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the turn serves.
   *
   * @return static
   *   This agent.
   */
  public function forEditorialSession(
    NeuronWorkflowManagerInterface $workflows,
    LoggerChannelInterface $logger,
    ContentCreationSessionInterface $session,
  ): static {
    $this->workflows = $workflows;
    $this->logger = $logger;
    $this->session = $session;

    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * A graph is built fresh for every segment of a run, and a node is never
   * stored, so the node of this module is handed its services here rather
   * than reading them off the resources of the run.
   */
  protected function nodes(): array {
    return array_map(
      fn (NodeInterface $node): NodeInterface => $node instanceof AwaitToolResultsNode
        ? new DeferredCallsNode($this->workflows, $this->logger, $this->session)
        : $node,
      parent::nodes(),
    );
  }

}
