<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftGroupJobs;
use Drupal\oe_ai_assistant\Service\Drafting\DraftGroupRunner;
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
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftGroupJobs $jobs
   *   The jobs that draft the groups in parallel.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftGroupRunner $groupRunner
   *   Drafts one group in this process.
   * @param \Drupal\Core\Queue\QueueInterface $groupQueue
   *   The queue the workers read the group jobs from.
   * @param int|null $uid
   *   The user the draft runs as, or NULL when no user is known.
   */
  public function __construct(
    public readonly NeuronWorkflowManagerInterface $workflows,
    public readonly MessageStoreInterface $store,
    public readonly LoggerChannelInterface $logger,
    public readonly ContentCreationSessionInterface $session,
    public readonly DraftGroupJobs $jobs,
    public readonly DraftGroupRunner $groupRunner,
    public readonly QueueInterface $groupQueue,
    public readonly ?int $uid,
  ) {
    parent::__construct();
  }

}
