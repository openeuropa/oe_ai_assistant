<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Guard;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionWorkflowInterface;
use Drupal\state_machine\Guard\GuardInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowTransition;

/**
 * Lets only users holding the transition permission move a document.
 *
 * Guards decide what the media form offers and what a validated save
 * accepts. Code paths save without validation and set the state directly,
 * so the guard never blocks the extraction pipeline.
 */
final class DocumentExtractionGuard implements GuardInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function allowed(WorkflowTransition $transition, WorkflowInterface $workflow, EntityInterface $entity): bool {
    return $this->currentUser->hasPermission(DocumentExtractionWorkflowInterface::TRANSITION_PERMISSION);
  }

}
