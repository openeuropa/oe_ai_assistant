<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\Query\QueryInterface;
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
  ) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
    $this->redirectDestination = $redirect_destination;
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['bundle'] = $this->t('Bundle');
    $header['content_type'] = $this->t('Content type');
    $header['template_id'] = $this->t('Template');
    $header['node_id'] = $this->t('Node');
    $header['owner'] = $this->t('Owner');
    $header['status'] = $this->t('Status');
    $header['created'] = $this->t('Created');
    $header['changed'] = $this->t('Changed');

    return $header + parent::buildHeader();
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
    $row['bundle'] = $entity->bundle();
    $row['content_type'] = $entity->get('content_type')->value ?? '';
    $row['template_id'] = $entity->get('template_id')->value ?? '';
    $row['node_id'] = $this->buildNodeCell($entity);
    $row['owner']['data'] = [
      '#theme' => 'username',
      '#account' => $entity->getOwner(),
    ];
    $row['status'] = $entity->getStatus();
    $row['created'] = $this->dateFormatter->format((int) $entity->get('created')->value, 'short');
    $row['changed'] = $this->dateFormatter->format((int) $entity->get('changed')->value, 'short');

    return $row + parent::buildRow($entity);
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
  protected function getDefaultOperations(EntityInterface $entity/* , ?CacheableMetadata $cacheability = NULL */): array {
    $args = func_get_args();
    $cacheability = $args[1] ?? new CacheableMetadata();

    $operations = parent::getDefaultOperations($entity, $cacheability);
    unset($operations['view']);

    $view_access = $entity->access('view', return_as_object: TRUE);
    $cacheability->addCacheableDependency($view_access);
    if ($view_access->isAllowed() && $entity->hasLinkTemplate('canonical')) {
      $operations['continue'] = [
        'title' => $this->t('Continue'),
        'weight' => 0,
        'url' => $entity->toUrl('canonical'),
      ];
    }

    return $operations;
  }

  /**
   * Builds the node column cell.
   */
  protected function buildNodeCell(EntityInterface $entity): array|string {
    $node = $entity->get('node_id')->entity;
    if (!$node) {
      return '';
    }

    return [
      'data' => [
        '#type' => 'link',
        '#title' => $node->label(),
        '#url' => $node->toUrl(),
      ],
    ];
  }

}
