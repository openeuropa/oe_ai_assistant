<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeTypeInterface;

/**
 * Hook implementations for the OpenEuropa AI Editorial Assistant module.
 */
final class OeAiAssistantHooks implements SessionAccessQueryAlterInterface {

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
    private readonly ModuleHandlerInterface $moduleHandler,
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
      // Mirrors checkSessionAccess(): the create-permission fallback only
      // applies while the session has no node. Testing the joined
      // node_field_data row rather than the raw node_target_id column is
      // what makes a deleted node read the same as no node at all: the join
      // finds no row for a nid that no longer exists, so it comes back NULL
      // exactly as getNode() does.
      $query->leftJoin('ai_editorial_session__content_type', 'session_content_type', '%alias.entity_id = base_table.id');
      $query->leftJoin('ai_editorial_session__node', 'content_type_node', '%alias.entity_id = base_table.id');
      $query->leftJoin('node_field_data', 'content_type_node_data', '%alias.nid = content_type_node.node_target_id AND %alias.default_langcode = 1');
      $access->condition($query->andConditionGroup()
        ->condition('session_content_type.content_type_target_id', $creatable_types, 'IN')
        ->isNull('content_type_node_data.nid'));
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
   * Built in SQL instead of loading every node and calling ->access() on
   * each, since this runs once per user per request. The grants subquery is
   * only added when a module implements hook_node_grants(), otherwise the
   * node_access table has no update grants and an unconditional join would
   * match every node; its preExecute() is called directly since a subquery
   * used only as a condition value never reaches hook_query_node_access_alter()
   * otherwise. Can't reflect a hook_node_access() implementation that forbids
   * 'update' outside permissions/grants (e.g. content_moderation) -- accepted
   * gap, the caller treats this as a fallback tier alongside real checks.
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

    if ($this->currentUser->hasPermission('bypass node access')) {
      return $this->updatableSessionNodeIdsByUser[$uid] = array_map('intval', $nids);
    }

    if (!$this->currentUser->hasPermission('access content')) {
      return $this->updatableSessionNodeIdsByUser[$uid] = [];
    }

    $query = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid'])
      ->condition('n.nid', $nids, 'IN')
      ->distinct();

    $updatable = $query->orConditionGroup();
    $has_tier = FALSE;
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $node_type) {
      if (!$node_type instanceof NodeTypeInterface) {
        continue;
      }
      $type_id = $node_type->id();
      if ($this->currentUser->hasPermission('edit any ' . $type_id . ' content')) {
        $updatable->condition('n.type', $type_id);
        $has_tier = TRUE;
      }
      elseif ($this->currentUser->hasPermission('edit own ' . $type_id . ' content')) {
        $updatable->condition($query->andConditionGroup()
          ->condition('n.type', $type_id)
          ->condition('n.uid', $uid));
        $has_tier = TRUE;
      }
    }

    if ($this->moduleHandler->hasImplementations('node_grants')) {
      $grants = $this->database->select('node_field_data', 'g')
        ->fields('g', ['nid'])
        ->condition('g.nid', $nids, 'IN');
      $grants->addTag('node_access');
      $grants->addMetaData('op', 'update');
      $grants->addMetaData('account', $this->currentUser);
      $grants->preExecute();
      $updatable->condition('n.nid', $grants, 'IN');
      $has_tier = TRUE;
    }

    if (!$has_tier) {
      return $this->updatableSessionNodeIdsByUser[$uid] = [];
    }

    $accessible = array_map('intval', $query->condition($updatable)->execute()->fetchCol());
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

    $access = $query->orConditionGroup()
      ->condition('base_table.host_entity_type', 'ai_editorial_session', '!=');

    if ($this->currentUser->hasPermission('administer ai editorial sessions')) {
      // Every session is accessible; no host_entity_id restriction needed.
      $access->condition('base_table.host_entity_type', 'ai_editorial_session');
    }
    else {
      // A subquery used as a condition value is never itself executed, so
      // it never runs through hook_query_ai_editorial_session_access_alter.
      // Calling the alter directly, rather than tagging the subquery, is
      // what actually scopes it instead of matching every session.
      $accessible_sessions = $this->database->select('ai_editorial_session', 'base_table')
        ->fields('base_table', ['id']);
      $this->queryAiEditorialSessionAccessAlter($accessible_sessions);

      $access->condition($query->andConditionGroup()
        ->condition('base_table.host_entity_type', 'ai_editorial_session')
        ->condition('base_table.host_entity_id', $accessible_sessions, 'IN'));
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
