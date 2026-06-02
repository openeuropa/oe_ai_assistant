<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionPluginInterface;

/**
 * Access control handler for AI editorial session plugin instances.
 */
class AiEditorialSessionPluginAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if (!$entity instanceof AiEditorialSessionPluginInterface) {
      return AccessResult::neutral();
    }

    $admin_access = AccessResult::allowedIfHasPermission($account, 'administer ai editorial sessions');
    if ($admin_access->isAllowed()) {
      return $admin_access;
    }

    try {
      $session = $entity->getSession();
    }
    catch (\UnexpectedValueException) {
      return AccessResult::forbidden()
        ->addCacheableDependency($entity);
    }

    return match ($operation) {
      'view', 'view label', 'update' => AccessResult::allowedIf($session->access($operation, $account))
        ->addCacheableDependency($entity)
        ->addCacheableDependency($session),
      'delete' => AccessResult::neutral()
        ->addCacheableDependency($entity)
        ->cachePerPermissions(),
      default => AccessResult::neutral()->addCacheableDependency($entity),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer ai editorial sessions');
  }

}
