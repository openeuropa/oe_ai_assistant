<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists AI interaction logs.
 */
class AiInteractionLogListBuilder extends EntityListBuilder {

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new list builder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    DateFormatterInterface $date_formatter,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
    );
  }

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
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['created'] = $this->t('Created');
    $header['provider'] = $this->t('Provider');
    $header['model'] = $this->t('Model');
    $header['event_name'] = $this->t('Event');
    $header['operation_type'] = $this->t('Operation');
    $header['provider_request_id'] = $this->t('Provider request ID');
    $header['user'] = $this->t('User');
    $header['total_tokens'] = $this->t('Total tokens');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\oe_ai_assistant\Entity\AiInteractionLogInterface $entity */
    $row['created'] = $this->dateFormatter->format((int) $entity->get('created')->value, 'short');
    $row['provider'] = $entity->get('provider')->value ?? '';
    $row['model'] = $entity->get('model')->value ?? '';
    $row['event_name'] = $entity->get('event_name')->value ?? '';
    $row['operation_type'] = $entity->get('operation_type')->value ?? '';
    $row['provider_request_id'] = $entity->getProviderRequestId() ?? '';
    $row['user'] = $this->buildUserLabel($entity);
    $row['total_tokens'] = $entity->get('total_tokens')->value ?? '';

    return $row + parent::buildRow($entity);
  }

  /**
   * Builds the displayed user value for a log row.
   */
  protected function buildUserLabel(EntityInterface $entity): string {
    $uid = $entity->get('user_id')->value;
    if ($uid === NULL || $uid === '') {
      return '';
    }

    $account = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $uid);

    if ($account === NULL) {
      return (string) $uid;
    }

    return sprintf('%s (%s)', $account->label(), $uid);
  }

}
