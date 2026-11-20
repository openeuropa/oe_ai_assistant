<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Workflow\WorkflowResources;

/**
 * What the nodes of one draft share.
 */
final class DraftContentResources extends WorkflowResources {

  /**
   * Builds the resources.
   *
   * @param \Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface $workflows
   *   Builds the drafter of one group.
   * @param \NeuronAI\Chat\History\MessageStoreInterface $store
   *   Where the draft is written. It is bound to the session, so its rows
   *   record the session and the version of the draft.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Records a group the run could not write.
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the draft belongs to.
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
