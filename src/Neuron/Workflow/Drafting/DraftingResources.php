<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Drafting;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use NeuronAI\Workflow\WorkflowResources;

/**
 * What the nodes of one group's drafting share.
 *
 * A run is built again for every segment, so the services reach the nodes
 * from here rather than from a constructor. The group and its schema
 * configure one node each, so they stay on those nodes.
 */
final class DraftingResources extends WorkflowResources {

  /**
   * Builds the resources.
   *
   * @param \Drupal\ai_neuron\Agent\NeuronAgentManagerInterface $agents
   *   Builds the drafter of the group.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the draft belongs to.
   */
  public function __construct(
    public readonly NeuronAgentManagerInterface $agents,
    public readonly AiEditorialSessionInterface $session,
  ) {
    parent::__construct();
  }

}
