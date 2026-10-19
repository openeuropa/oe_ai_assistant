<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeTypeInterface;

/**
 * Hook implementations for the OpenEuropa AI Editorial Assistant module.
 */
final class OeAiAssistantHooks {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_query_ai_editorial_session_access_alter().
   *
   * Mirrors AiEditorialSessionAccessControlHandler::checkSessionAccess() at
   * the SQL level, so entity queries (admin collection, autocomplete, Views)
   * surface the same sessions the entity-level access() check would allow:
   * the owner, collaborators via the content_type create permission, and
   * collaborators via node access (reusing Drupal's own node access grants
   * system through the standard 'node_access' query tag).
   */
  #[Hook('query_ai_editorial_session_access_alter')]
  public function queryAiEditorialSessionAccessAlter(AlterableInterface $query): void {
    if (!$query instanceof SelectInterface) {
      return;
    }

    if ($this->currentUser->hasPermission('administer ai editorial sessions')) {
      return;
    }

    // 'use oe ai assistant' gates all session access; without it
    // checkSessionAccess() always forbids, so exclude every row.
    if (!$this->currentUser->hasPermission('use oe ai assistant')) {
      $query->alwaysFalse();
      return;
    }

    $access = $query->orConditionGroup()
      ->condition('base_table.uid', (int) $this->currentUser->id());

    $creatable_types = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $node_type) {
      if ($node_type instanceof NodeTypeInterface && $this->currentUser->hasPermission(sprintf('create %s content', $node_type->id()))) {
        $creatable_types[] = $node_type->id();
      }
    }
    if ($creatable_types !== []) {
      $query->leftJoin('ai_editorial_session__content_type', 'session_content_type', '%alias.entity_id = base_table.id');
      $access->condition('session_content_type.content_type_target_id', $creatable_types, 'IN');
    }

    $query->leftJoin('ai_editorial_session__node', 'session_node', '%alias.entity_id = base_table.id');
    $query->leftJoin('node_field_data', 'session_node_data', '%alias.nid = session_node.node_target_id');
    $access->condition('session_node_data.nid', NULL, 'IS NOT NULL');
    $query->addTag('node_access')->addMetaData('base_table', 'node_field_data');

    $query->condition($access);
  }

  /**
   * Implements hook_options_list_alter().
   *
   * Check create permissions to create the node types selector.
   *
   * @param array<int,mixed> $options
   *   Node types options.
   * @param array<int,mixed> $context
   *   Context of the call.
   */
  #[Hook('options_list_alter')]
  public function nodeTypesOptionsAccess(array &$options, array $context): void {
    if (!isset($context['entity']) || $context['entity']->getEntityType()->id() !== 'ai_editorial_session') {
      return;
    }
    $settings = $context['fieldDefinition']?->getSettings();
    if (isset($settings['target_type']) && $settings['target_type'] == 'node_type') {
      $accessHandler = \Drupal::entityTypeManager()->getAccessControlHandler('node');
      $valid_options = [];
      foreach ($options as $type => $option) {
        if ($accessHandler->createAccess($type)) {
          $valid_options[$type] = $option;
        }
      }
      $options = $valid_options;
    }
  }

}
