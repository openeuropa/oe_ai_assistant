<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for AI conversation messages.
 *
 * Admins (administer ai conversation messages) get every operation. Otherwise
 * each operation maps to its own permission.
 */
class AiConversationMessageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    $admin = AccessResult::allowedIfHasPermission($account, 'administer ai conversation messages');
    if ($admin->isAllowed()) {
      return $admin;
    }

    $permission = match ($operation) {
      'view', 'view label' => 'access ai conversation message overview',
      'update' => 'edit ai conversation message',
      'delete' => 'delete ai conversation message',
      default => NULL,
    };
    if ($permission === NULL) {
      return AccessResult::neutral();
    }

    return AccessResult::allowedIfHasPermission($account, $permission);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermissions(
      $account,
      ['administer ai conversation messages', 'create ai conversation message'],
      'OR'
    );
  }

}
