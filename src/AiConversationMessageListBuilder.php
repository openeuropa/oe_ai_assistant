<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Entity\EntityAccessControlHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Form\AiConversationMessageFilterForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lists AI conversation messages with sortable columns and token totals.
 */
class AiConversationMessageListBuilder extends EntityListBuilder {

  /**
   * The token usage fields, aggregated into the totals row.
   */
  private const TOKEN_FIELDS = [
    'tokens_input',
    'tokens_output',
    'tokens_total',
    'tokens_reasoning',
    'tokens_cached',
  ];

  /**
   * The form builder.
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The access control handler.
   */
  protected EntityAccessControlHandlerInterface $accessHandler;

  /**
   * Constructs a new list builder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    FormBuilderInterface $form_builder,
    RequestStack $request_stack,
    EntityAccessControlHandlerInterface $access_handler,
  ) {
    parent::__construct($entity_type, $storage);
    $this->formBuilder = $form_builder;
    $this->requestStack = $request_stack;
    $this->accessHandler = $access_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $entity_type_manager = $container->get('entity_type.manager');
    return new static(
      $entity_type,
      $entity_type_manager->getStorage($entity_type->id()),
      $container->get('form_builder'),
      $container->get('request_stack'),
      $entity_type_manager->getAccessControlHandler($entity_type->id()),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    $query = $this->getStorage()->getQuery()->accessCheck(TRUE);
    $this->applyFilters($query);
    $header = $this->buildHeader();
    $query->tableSort($header);
    $query->pager($this->limit);
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->sortableHeader($this->t('ID'), 'id');
    $header['host_entity_type'] = $this->sortableHeader($this->t('Host type'), 'host_entity_type');
    $header['host_entity_id'] = $this->sortableHeader($this->t('Host id'), 'host_entity_id');
    $header['role'] = $this->sortableHeader($this->t('Role'), 'role');
    $header['agent_id'] = $this->sortableHeader($this->t('Agent'), 'agent_id');
    $header['provider'] = $this->sortableHeader($this->t('Provider'), 'provider');
    $header['model'] = $this->sortableHeader($this->t('Model'), 'model');
    $header['tokens_input'] = $this->sortableHeader($this->t('In'), 'tokens_input');
    $header['tokens_output'] = $this->sortableHeader($this->t('Out'), 'tokens_output');
    $header['tokens_total'] = $this->sortableHeader($this->t('Total'), 'tokens_total');
    $header['tokens_reasoning'] = $this->sortableHeader($this->t('Reasoning'), 'tokens_reasoning');
    $header['tokens_cached'] = $this->sortableHeader($this->t('Cached'), 'tokens_cached');
    $header['created'] = $this->sortableHeader($this->t('Created'), 'created', 'desc');
    $header['operations'] = $this->t('Operations');
    return $header;
  }

  /**
   * Builds a sortable header cell for a field.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The column label.
   * @param string $field
   *   The entity field to sort by.
   * @param string|null $sort
   *   The default sort direction ("asc"/"desc"), or NULL.
   *
   * @return array
   *   The header cell definition.
   */
  protected function sortableHeader($label, string $field, ?string $sort = NULL): array {
    $cell = ['data' => $label, 'specifier' => $field, 'field' => $field];
    if ($sort !== NULL) {
      $cell['sort'] = $sort;
    }
    return $cell;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof AiConversationMessageInterface);
    $row['id'] = $entity->id();
    $row['host_entity_type'] = $entity->getHostEntityType();
    $row['host_entity_id'] = $entity->getHostEntityId();
    $row['role'] = $entity->getRole();
    $row['agent_id'] = $entity->get('agent_id')->value;
    $row['provider'] = $entity->get('provider')->value;
    $row['model'] = $entity->get('model')->value;
    $row['tokens_input'] = $entity->get('tokens_input')->value;
    $row['tokens_output'] = $entity->get('tokens_output')->value;
    $row['tokens_total'] = $entity->get('tokens_total')->value;
    $row['tokens_reasoning'] = $entity->get('tokens_reasoning')->value;
    $row['tokens_cached'] = $entity->get('tokens_cached')->value;
    $row['created'] = $entity->get('created')->value;
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build['add'] = [
      '#type' => 'link',
      '#title' => $this->t('Add conversation message'),
      '#url' => Url::fromRoute('entity.ai_conversation_message.add_form'),
      '#access' => $this->accessHandler->createAccess(NULL, NULL, [], TRUE),
      '#attributes' => ['class' => ['button', 'button--action', 'button--primary']],
    ];
    $build['filters'] = $this->formBuilder->getForm(AiConversationMessageFilterForm::class);
    $build += parent::render();
    $build['table']['#footer'][] = $this->buildTotalsRow();
    return $build;
  }

  /**
   * Applies the request filters to a query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query (list or aggregate) to constrain.
   */
  protected function applyFilters(QueryInterface $query): void {
    $params = $this->requestStack->getCurrentRequest()->query;

    $host_type = trim((string) $params->get('host_entity_type', ''));
    if ($host_type !== '') {
      $query->condition('host_entity_type', $host_type, 'CONTAINS');
    }

    $host_id = $params->get('host_entity_id');
    if ($host_id !== NULL && $host_id !== '') {
      $query->condition('host_entity_id', (int) $host_id);
    }

    $role = trim((string) $params->get('role', ''));
    if ($role !== '') {
      $query->condition('role', $role);
    }

    $provider = trim((string) $params->get('provider', ''));
    if ($provider !== '') {
      $query->condition('provider', $provider, 'CONTAINS');
    }

    $agent_id = trim((string) $params->get('agent_id', ''));
    if ($agent_id !== '') {
      $query->condition('agent_id', $agent_id, 'CONTAINS');
    }
  }

  /**
   * Builds the table footer row summing the token columns (filtered set).
   *
   * @return array
   *   A table footer row aligned to the header columns.
   */
  protected function buildTotalsRow(): array {
    $query = $this->getStorage()->getAggregateQuery()->accessCheck(TRUE);
    $this->applyFilters($query);
    foreach (self::TOKEN_FIELDS as $field) {
      $query->aggregate($field, 'SUM');
    }
    $result = $query->execute();
    $sums = $result[0] ?? [];

    $row = [
      ['data' => $this->t('Totals (filtered)'), 'colspan' => 7, 'header' => TRUE],
    ];
    foreach (self::TOKEN_FIELDS as $field) {
      $row[] = (string) (int) ($sums[$field . '_sum'] ?? 0);
    }
    $row[] = ['data' => '', 'colspan' => 2];

    return $row;
  }

}
