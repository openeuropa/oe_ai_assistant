<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Subagent;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Tells which account may run a task for a session.
 */
final class TaskAccount {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads the user of a task, if the user may still run it.
   *
   * @param int|null $uid
   *   The id of the user the task runs for.
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The session the task belongs to.
   *
   * @return \Drupal\user\UserInterface|null
   *   The user, or NULL when the user is missing, blocked, without the
   *   permission, or without access to the session.
   */
  public function resolve(?int $uid, EntityInterface $session): ?UserInterface {
    $user = $uid === NULL ? NULL : $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || !$user->isActive() || !$user->hasPermission('use oe ai assistant') || !$session->access('view', $user)) {
      return NULL;
    }

    return $user;
  }

}
