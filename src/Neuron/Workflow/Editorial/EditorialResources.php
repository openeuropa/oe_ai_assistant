<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Editorial;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Workflow\WorkflowResources;

/**
 * What the nodes of an editorial turn share.
 *
 * A run is built again for every segment, so the services reach the nodes
 * from here rather than from a constructor. What configures one node, such as
 * the agent of the turn, stays on that node.
 */
final class EditorialResources extends WorkflowResources {

  /**
   * Builds the resources.
   *
   * @param \Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface $workflows
   *   Builds the drafter of one group.
   * @param \NeuronAI\Chat\History\MessageStoreInterface $store
   *   Where a draft is written.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Records a group the run could not write.
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the turn serves.
   */
  public function __construct(
    public readonly NeuronWorkflowManagerInterface $workflows,
    public readonly MessageStoreInterface $store,
    public readonly LoggerChannelInterface $logger,
    public readonly ContentCreationSessionInterface $session,
  ) {
    parent::__construct();
  }

}
