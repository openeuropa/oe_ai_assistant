<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
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
   * @param \NeuronAI\Workflow\Persistence\PersistenceInterface $persistence
   *   Where the site keeps its runs. A turn drops the run an earlier turn
   *   left suspended.
   * @param \NeuronAI\Chat\History\MessageStoreInterface $store
   *   Where the run writes a draft. A caller binds the session to it, so
   *   its rows carry the reference and the version.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Records a group the run could not write, and a run the turn dropped.
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the turn serves.
   */
  public function __construct(
    public readonly NeuronWorkflowManagerInterface $workflows,
    public readonly PersistenceInterface $persistence,
    public readonly MessageStoreInterface $store,
    public readonly LoggerChannelInterface $logger,
    public readonly ContentCreationSessionInterface $session,
  ) {
    parent::__construct();
  }

}
