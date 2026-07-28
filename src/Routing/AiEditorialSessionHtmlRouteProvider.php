<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Routing;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Symfony\Component\Routing\Route;

/**
 * Provides admin HTML routes for AI editorial sessions.
 */
class AiEditorialSessionHtmlRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  public function getRoutes(EntityTypeInterface $entity_type) {
    $collection = parent::getRoutes($entity_type);

    if ($history_route = $this->getHistoryRoute($entity_type)) {
      $collection->add('entity.ai_editorial_session.history', $history_route);
    }

    return $collection;
  }

  /**
   * Gets the conversation history route, built from the history link template.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return \Symfony\Component\Routing\Route|null
   *   The generated route, if the entity type declares the link template.
   */
  protected function getHistoryRoute(EntityTypeInterface $entity_type): ?Route {
    if (!$entity_type->hasLinkTemplate('history')) {
      return NULL;
    }
    $entity_type_id = $entity_type->id();
    $route = new Route($entity_type->getLinkTemplate('history'));
    $route
      ->setDefaults([
        '_controller' => '\Drupal\oe_ai_assistant\Controller\AiConversationHistoryController::view',
        '_title_callback' => '\Drupal\oe_ai_assistant\Controller\AiConversationHistoryController::title',
      ])
      ->setRequirement('_permission', 'access ai conversation message overview+administer ai conversation messages')
      ->setOption('_admin_route', TRUE)
      ->setOption('parameters', [
        $entity_type_id => ['type' => 'entity:' . $entity_type_id],
      ]);

    return $route;
  }

  /**
   * {@inheritdoc}
   */
  protected function getAddPageRoute(EntityTypeInterface $entity_type): ?Route {
    $route = parent::getAddPageRoute($entity_type);
    if ($route) {
      $route
        ->setDefault('_controller', '\Drupal\oe_ai_assistant\Controller\AiEditorialSessionController::addPage')
        ->setDefault('_title', 'Add AI editorial session')
        ->setOption('_admin_route', TRUE);
    }

    return $route;
  }

  /**
   * {@inheritdoc}
   */
  protected function getCanonicalRoute(EntityTypeInterface $entity_type): ?Route {
    if ($entity_type->hasLinkTemplate('canonical')) {
      $entity_type_id = $entity_type->id();
      $route = new Route($entity_type->getLinkTemplate('canonical'));
      $route
        ->setDefaults([
          '_controller' => '\Drupal\oe_ai_assistant\Controller\AiEditorialSessionController::view',
          '_title_callback' => '\Drupal\Core\Entity\Controller\EntityController::title',
        ])
        ->setRequirement('_entity_access', $entity_type_id . '.view')
        ->setOption('_admin_route', TRUE)
        ->setOption('parameters', [
          $entity_type_id => ['type' => 'entity:' . $entity_type_id],
        ]);

      return $route;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function getCollectionRoute(EntityTypeInterface $entity_type): ?Route {
    $route = parent::getCollectionRoute($entity_type);
    if ($route) {
      $route->setOption('_admin_route', TRUE);
    }

    return $route;
  }

}
