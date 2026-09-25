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
    $feature_access = AccessResult::allowedIfHasPermission($account, 'use oe ai assistant');
    if (!$feature_access->isAllowed()) {
      return AccessResult::forbidden()->inheritCacheability($feature_access)->addCacheableDependency($entity);
    }

    $node_operation = $operation === 'update' ? 'update' : 'view';

    $access = AccessResult::neutral()->addCacheableDependency($entity);

    $node = $entity->getNode();
    if ($node !== NULL) {
      $access = $access->orIf(
        AccessResult::allowedIf($node->access($node_operation, $account))
          ->addCacheableDependency($node)
      );
    }

    // If there is no node related with entity use session's content type for access check.
    $content_type = $entity->getContentType();
    if ($content_type !== '') {
      $access = $access->orIf(AccessResult::allowedIfHasPermission($account, sprintf('create %s content', $content_type)));
    }

    // If content type cannot be determined for some reason - owner check.
    $access = $access->orIf(
      AccessResult::allowedIf((int) $entity->getOwnerId() === (int) $account->id())->cachePerUser()
    );

    return $access->isAllowed() ? $access : AccessResult::forbidden()->inheritCacheability($access);
  }

}
