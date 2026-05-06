<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Access control handler for AI editorial sessions.
 */
class AiEditorialSessionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if (!$entity instanceof AiEditorialSessionInterface) {
      return AccessResult::neutral();
    }

    $admin_access = AccessResult::allowedIfHasPermission($account, 'administer ai editorial sessions');
    if ($admin_access->isAllowed()) {
      return $admin_access;
    }

    return match ($operation) {
      'view', 'view label', 'update' => $this->checkSessionAccess($entity, $account, $operation),
      'delete' => AccessResult::neutral()->addCacheableDependency($entity)->cachePerPermissions(),
      default => AccessResult::neutral()->addCacheableDependency($entity),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    $admin_access = AccessResult::allowedIfHasPermission($account, 'administer ai editorial sessions');
    if ($admin_access->isAllowed()) {
      return $admin_access;
    }

    $content_type = $context['content_type'] ?? NULL;
    if (is_string($content_type) && $content_type !== '') {
      return AccessResult::allowedIfHasPermission($account, sprintf('create %s content', $content_type));
    }

    $result = AccessResult::neutral()->cachePerPermissions();
    $node_types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    foreach ($node_types as $node_type) {
      if (!$node_type instanceof NodeTypeInterface) {
        continue;
      }
      $result = $result->orIf(AccessResult::allowedIfHasPermission($account, sprintf('create %s content', $node_type->id())));
    }

    return $result;
  }

  /**
   * Checks access to an existing session.
   */
  protected function checkSessionAccess(AiEditorialSessionInterface $entity, AccountInterface $account, string $operation): AccessResult {
    $owner_access = AccessResult::allowedIf((int) $entity->getOwnerId() === (int) $account->id())
      ->addCacheableDependency($entity)
      ->cachePerUser();
    if ($owner_access->isAllowed()) {
      return $owner_access;
    }

    $node = $entity->get('node_id')->entity;
    if (!$node) {
      return AccessResult::forbidden()
        ->addCacheableDependency($entity)
        ->cachePerUser();
    }

    $node_operation = $operation === 'update' ? 'update' : 'view';
    $node_access = $node->access($node_operation, $account, TRUE)
      ->addCacheableDependency($entity);
    if ($node_access->isAllowed()) {
      return $node_access;
    }

    return AccessResult::forbidden()
      ->addCacheableDependency($entity)
      ->addCacheableDependency($node_access)
      ->cachePerUser();
  }

}
