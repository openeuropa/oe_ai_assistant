<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;

/**
 * Hook implementations for the OpenEuropa AI Editorial Assistant module.
 */
final class OeAiAssistantHooks {

  /**
   * The updatable session node IDs, keyed by user ID.
   *
   * @var array<int,array<int,int>>
   */
  private array $updatableSessionNodeIdsByUser = [];

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Implements hook_query_ai_editorial_session_access_alter().
   *
   * Mirrors AiEditorialSessionAccessControlHandler::checkSessionAccess(): the
   * owner, collaborators via the content_type create permission, and
   * collaborators with node update access.
   *
   * Node access goes through getUpdatableSessionNodeIds() rather than the
   * 'node_access' query tag: adding that tag to $query from inside this same
   * alter is a no-op, since Select::preExecute() already fixed the list of
   * alter hooks to run before this one started.
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

    $updatable_nids = $this->getUpdatableSessionNodeIds();
    if ($updatable_nids !== []) {
      $query->leftJoin('ai_editorial_session__node', 'session_node', '%alias.entity_id = base_table.id');
      $access->condition('session_node.node_target_id', $updatable_nids, 'IN');
    }

    $query->condition($access);
  }

  /**
   * Resolves the nodes, among those attached to a session, the user may update.
   *
   * Uses the real node access handler instead of the node_access grants
   * table directly, since that table alone doesn't cover the base
   * 'edit own/any' permission.
   *
   * @return array<int,int>
   *   The accessible node IDs.
   */
  private function getUpdatableSessionNodeIds(): array {
    $uid = (int) $this->currentUser->id();
    if (isset($this->updatableSessionNodeIdsByUser[$uid])) {
      return $this->updatableSessionNodeIdsByUser[$uid];
    }

    $nids = $this->database->select('ai_editorial_session__node', 'session_node')
      ->fields('session_node', ['node_target_id'])
      ->distinct()
      ->execute()
      ->fetchCol();
    if ($nids === []) {
      return $this->updatableSessionNodeIdsByUser[$uid] = [];
    }

    $accessible = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($nids) as $node) {
      if ($node instanceof NodeInterface && $node->access('update', $this->currentUser)) {
        $accessible[] = (int) $node->id();
      }
    }
    return $this->updatableSessionNodeIdsByUser[$uid] = $accessible;
  }

  /**
   * Implements hook_query_ai_conversation_message_access_alter().
   *
   * Scopes the admin listing and its token totals to sessions the viewer can
   * access, mirroring AiConversationMessageAccessControlHandler.
   */
  #[Hook('query_ai_conversation_message_access_alter')]
  public function queryAiConversationMessageAccessAlter(AlterableInterface $query): void {
    if (!$query instanceof SelectInterface) {
      return;
    }

    if ($this->currentUser->hasPermission('administer ai conversation messages')) {
      return;
    }

    if (!$this->currentUser->hasPermission('access ai conversation message overview')) {
      $query->alwaysFalse();
      return;
    }

    $accessible_session_ids = $this->entityTypeManager->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(TRUE)
      ->execute();

    $access = $query->orConditionGroup()
      ->condition('base_table.host_entity_type', 'ai_editorial_session', '!=');
    if ($accessible_session_ids !== []) {
      $access->condition($query->andConditionGroup()
        ->condition('base_table.host_entity_type', 'ai_editorial_session')
        ->condition('base_table.host_entity_id', array_values($accessible_session_ids), 'IN'));
    }

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
