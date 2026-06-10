<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
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
   * Constructs a new list builder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    DateFormatterInterface $date_formatter,
  ) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
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
    $row['total_tokens'] = $entity->get('total_tokens')->value ?? '';

    return $row + parent::buildRow($entity);
  }

}
