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
      'view', 'view label', 'update' => $this->checkSessionAccess($entity, $account),
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

    // Gate creation behind the same feature permission required to view or
    // use a session afterwards, so a session is never left orphaned by a
    // creator who cannot subsequently access it.
    $feature_access = AccessResult::allowedIfHasPermission($account, 'use oe ai assistant');
    if (!$feature_access->isAllowed()) {
      return $feature_access;
    }

    $content_type = $context['content_type'] ?? NULL;
    if (is_string($content_type) && $content_type !== '') {
      return $feature_access->andIf(AccessResult::allowedIfHasPermission($account, sprintf('create %s content', $content_type)));
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

    return $feature_access->andIf($result);
  }

  /**
   * Checks access to an existing session.
   */
  protected function checkSessionAccess(AiEditorialSessionInterface $entity, AccountInterface $account): AccessResult {
    $feature_access = AccessResult::allowedIfHasPermission($account, 'use oe ai assistant');
    if (!$feature_access->isAllowed()) {
      return AccessResult::forbidden()->inheritCacheability($feature_access)->addCacheableDependency($entity);
    }

    $access = AccessResult::neutral()->addCacheableDependency($entity);

    $node = $entity->getNode();
    if ($node !== NULL) {
      // Always node 'update', even for the session 'view' op: the session
      // hosts pre-publication drafts, so a plain node viewer must not read
      // them. Fold in by bool outcome rather than orIf()'ing directly: node
      // access returns an authoritative forbidden for accounts lacking
      // 'access content', which would veto the fallback tiers below.
      $node_access = $node->access('update', $account, TRUE);
      $access = $access
        ->orIf(AccessResult::allowedIf($node_access->isAllowed()))
        ->addCacheableDependency($node_access)
        ->addCacheableDependency($node);
    }

    // Standing fallback alongside node access, but only while the session has
    // no node: once a node exists, collaboration requires real node access.
    if ($node === NULL) {
      $content_type = $entity->getContentType();
      if ($content_type !== '') {
        $access = $access->orIf(AccessResult::allowedIfHasPermission($account, sprintf('create %s content', $content_type)));
      }
    }

    // If content type cannot be determined for some reason - owner check.
    $access = $access->orIf(
      AccessResult::allowedIf((int) $entity->getOwnerId() === (int) $account->id())->cachePerUser()
    );

    // Neutral, not forbidden: forbidden() would win over an allowed from a
    // downstream hook_ai_editorial_session_access() via orIf(), permanently
    // locking it out.
    return $access->isAllowed() ? $access : AccessResult::neutral()->inheritCacheability($access);
  }

}
