<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for AI editorial session activity entries.
 */
class AiEditorialSessionActivityAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    // An entry reports something that already happened, and nothing marks
    // an entry as edited. So we forbid an update here, before the parent
    // allows it to anyone with the administration permission.
    if ($operation === 'update') {
      return AccessResult::forbidden();
    }

    return parent::checkAccess($entity, $operation, $account);
  }

}
