<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists AI editorial sessions.
 */
class AiEditorialSessionListBuilder extends EntityListBuilder {

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The entity type manager, for resolving referenced entity labels.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The moderation information service.
   */
  protected ModerationInformationInterface $moderationInformation;

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    return $this->getStorage()
      ->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->pager($this->limit);
  }

  /**
   * Constructs a new list builder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    DateFormatterInterface $date_formatter,
    RedirectDestinationInterface $redirect_destination,
    EntityTypeManagerInterface $entity_type_manager,
    ModerationInformationInterface $moderation_information,
  ) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
    $this->redirectDestination = $redirect_destination;
    $this->entityTypeManager = $entity_type_manager;
    $this->moderationInformation = $moderation_information;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
      $container->get('redirect.destination'),
      $container->get('entity_type.manager'),
      $container->get('content_moderation.moderation_information'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Session');
    $header['bundle'] = $this->t('Type');
    $header['content_type'] = $this->t('Target');
    $header['node'] = $this->t('Node');
    $header['creator'] = $this->t('Initiated by');
    $header['status'] = $this->t('Status');
    $header['created'] = $this->t('Created');
    $header['changed'] = $this->t('Changed');
    $header['operations'] = $this->t('Operations');

    return $header;
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    return array_filter(
      parent::load(),
      static fn (EntityInterface $entity): bool => $entity->access('view')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $entity */
    $row['label']['data'] = [
      '#type' => 'link',
      '#title' => $entity->label(),
      '#url' => $entity->toUrl('canonical'),
    ];
    // Human readable labels only, never machine names: the session type,
    // the targeted node type, and the status all resolve to their labels.
    $bundle = $this->entityTypeManager
      ->getStorage('ai_editorial_session_type')
      ->load($entity->bundle());
    $row['bundle'] = (string) ($bundle?->label() ?? $entity->bundle());
    $target_id = $entity->get('content_type')->target_id ?? '';
    $node_type = $target_id === ''
      ? NULL
      : $this->entityTypeManager->getStorage('node_type')->load($target_id);
    $row['content_type'] = (string) ($node_type?->label() ?? $target_id);
    $row['node'] = $this->buildNodeCell($entity->getNode());
    $row['creator']['data'] = [
      '#theme' => 'username',
      '#account' => $entity->getOwner(),
    ];
    $allowed = $entity->getFieldDefinition('status')->getSetting('allowed_values');
    $row['status'] = (string) ($allowed[$entity->getStatus()] ?? $entity->getStatus());
    $row['created'] = $this->dateFormatter->format((int) $entity->get('created')->value, 'short');
    $row['changed'] = $this->dateFormatter->format((int) $entity->get('changed')->value, 'short');

    return $row + parent::buildRow($entity);
  }

  /**
   * Builds the node column cell: a link to the node, or empty.
   *
   * Empty when the session has no node, or the current user cannot view it.
   * Links to the latest version when the node has a pending revision.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The session's referenced node, or NULL.
   *
   * @return array|string
   *   A link render array, or an empty string.
   */
  protected function buildNodeCell(?NodeInterface $node): array|string {
    if ($node === NULL || !$node->access('view')) {
      return '';
    }

    return [
      'data' => [
        '#type' => 'link',
        '#title' => $node->label(),
        '#url' => $this->moderationInformation->hasPendingRevision($node)
          ? $node->toUrl('latest-version')
          : $node->toUrl('canonical'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build['add_new_session'] = [
      '#type' => 'link',
      '#title' => $this->t('Add new session'),
      '#url' => Url::fromRoute('entity.ai_editorial_session.add_page'),
      '#attributes' => [
        'class' => ['button', 'button--action', 'button--primary'],
      ],
    ];
    $build += parent::render();

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    unset($operations['view']);

    if ($entity->access('view') && $entity->hasLinkTemplate('canonical')) {
      $operations['continue'] = [
        'title' => $this->t('Continue'),
        'weight' => 0,
        'url' => $entity->toUrl('canonical'),
      ];
    }

    // Link to the conversation history tree, for users who may see messages.
    if ($entity->hasLinkTemplate('history')) {
      $history_url = $entity->toUrl('history');
      if ($history_url->access()) {
        $operations['history'] = [
          'title' => $this->t('History'),
          'weight' => 5,
          'url' => $history_url,
        ];
      }
    }

    return $operations;
  }

}
