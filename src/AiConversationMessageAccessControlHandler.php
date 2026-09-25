<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

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

    $flat_access = AccessResult::allowedIfHasPermission($account, $permission);

    if (!$entity instanceof AiConversationMessageInterface || $entity->getHostEntityType() !== 'ai_editorial_session') {
      return $flat_access;
    }

    $session = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session')
      ->load($entity->getHostEntityId());
    if (!$session instanceof AiEditorialSessionInterface) {
      return $flat_access;
    }

    $session_operation = $operation === 'view' || $operation === 'view label' ? 'view' : 'update';

    return $flat_access
      ->andIf($session->access($session_operation, $account, TRUE))
      ->addCacheableDependency($entity);
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
