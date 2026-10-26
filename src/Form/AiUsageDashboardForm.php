<?php

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\oe_ai_assistant\Service\UsageDashboardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides filters for the AI usage dashboard.
 */
class AiUsageDashboardForm extends FormBase {

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The entity type bundle information service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $dashboardRequestStack
   *   The request stack.
   * @param \Drupal\oe_ai_assistant\Service\UsageDashboardBuilder $dashboardBuilder
   *   The dashboard render array builder.
   */
  public function __construct(
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $dashboardRequestStack,
    protected UsageDashboardBuilder $dashboardBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get(UsageDashboardBuilder::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_usage_dashboard__form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $sessionTypes = array_map(
      static fn (array $bundle): string => (string) $bundle['label'],
      $this->entityTypeBundleInfo->getBundleInfo('ai_editorial_session'),
    );
    natcasesort($sessionTypes);
    $contentTypes = array_map(
      static fn (array $bundle): string => (string) $bundle['label'],
      $this->entityTypeBundleInfo->getBundleInfo('node'),
    );
    natcasesort($contentTypes);

    $query = $this->dashboardRequestStack->getCurrentRequest()->query->all();
    $startDate = isset($query['start']) && is_string($query['start']) ? $query['start'] : (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $endDate = isset($query['end']) && is_string($query['end']) ? $query['end'] : (new \DateTimeImmutable('last day of this month'))->format('Y-m-d');
    $preset = isset($query['preset']) && is_string($query['preset']) ? $query['preset'] : '30-days';
    $selectedSessionType = isset($query['session_type']) && is_string($query['session_type']) && isset($sessionTypes[$query['session_type']]) ? $query['session_type'] : '';
    $selectedContentType = isset($query['content_type']) && is_string($query['content_type']) && isset($contentTypes[$query['content_type']]) ? $query['content_type'] : '';

    $users = [];
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple() as $uid => $user) {
      $users[(string) $uid] = (string) $user->label();
    }
    natcasesort($users);
    $selectedUser = isset($query['participant']) && is_string($query['participant']) && isset($users[$query['participant']]) ? $query['participant'] : '';

    $form['session_type'] = [
      '#type' => 'select',
      '#title' => $this->t('AI session type'),
      '#options' => $sessionTypes,
      '#empty_option' => $this->t('All session types'),
      '#default_value' => $selectedSessionType,
    ];
    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#options' => $contentTypes,
      '#empty_option' => $this->t('All content types'),
      '#default_value' => $selectedContentType,
    ];
    $form['participant'] = [
      '#type' => 'select',
      '#title' => $this->t('Username'),
      '#options' => $users,
      '#empty_option' => $this->t('All users'),
      '#default_value' => $selectedUser,
    ];
    $form['start'] = [
      '#type' => 'date',
      '#title' => $this->t('Start date'),
      '#default_value' => $startDate,
    ];
    $form['end'] = [
      '#type' => 'date',
      '#title' => $this->t('End date'),
      '#default_value' => $endDate,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply filters'),
    ];
    $form['actions']['clear'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear filters'),
      '#limit_validation_errors' => [],
      '#submit' => ['::clearFilters'],
    ];

    // The builder owns the dashboard body; the form supplies the pieces that
    // depend on FAPI elements (header, filter bar, period tabs) and the date
    // range used by the live forecast card.
    $form['dashboard'] = $this->dashboardBuilder->build(
      $this->dashboardHeader(),
      $this->dashboardFilters($form),
      $this->dashboardPeriodControls($preset),
      $startDate,
      $endDate,
    );
    unset(
      $form['start'],
      $form['end'],
      $form['session_type'],
      $form['content_type'],
      $form['participant'],
      $form['actions'],
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $startDate = $form_state->getValue('start');
    $endDate = $form_state->getValue('end');

    if (($startDate === '') !== ($endDate === '')) {
      $form_state->setErrorByName('start', $this->t('Both start and end dates are required.'));
    }
    elseif ($startDate !== '' && $startDate > $endDate) {
      $form_state->setErrorByName('end', $this->t('The end date must not be before the start date.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $query = [
      'session_type' => $form_state->getValue('session_type'),
      'content_type' => $form_state->getValue('content_type'),
      'participant' => $form_state->getValue('participant'),
    ];
    $query = array_filter($query, static fn (string $value): bool => $value !== '');
    if ($form_state->getValue('start') !== '') {
      $query += [
        'preset' => 'custom',
        'start' => $form_state->getValue('start'),
        'end' => $form_state->getValue('end'),
      ];
    }

    $form_state->setRedirect('oe_ai_assistant.usage_dashboard', [], [
      'query' => $query,
    ]);
  }

  /**
   * Redirects to the dashboard with no filter query parameters.
   *
   * @param array $form
   *   The complete form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function clearFilters(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('oe_ai_assistant.usage_dashboard');
  }

  /**
   * Builds the dashboard header render array.
   *
   * @return array
   *   The render array.
   */
  protected function dashboardHeader(): array {
    return [
      '#type' => 'container',
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-dashboard__header-actions']],
        'compare' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Compare with previous month'),
          '#attributes' => ['class' => ['button'], 'type' => 'button'],
        ],
      ],
    ];
  }

  /**
   * Build the filters render array.
   *
   * @param array $form
   *   The complete form array.
   *
   * @return array
   *   The render array
   */
  protected function dashboardFilters(array $form): array {
    // Each control keeps its label but renders it inside the field box (an
    // inset label) to match the design; the date range is a single field that
    // holds the start and end inputs separated by a dash.
    return [
      '#type' => 'component',
      '#component' => 'oe_ai_assistant:dashboard-filter-bar',
      'filters' => [
        '#type' => 'container',
        'date_range' => [
          '#type' => 'fieldset',
          '#title' => $this->t('Date range'),
          '#attributes' => ['class' => ['ai-dashboard-filter-bar__field', 'ai-dashboard-filter-bar__date-range']],
          'start' => $form['start'] + ['#title_display' => 'invisible'],
          'end' => $form['end'] + ['#title_display' => 'invisible'],
        ],
        'session_type' => $form['session_type'] + ['#wrapper_attributes' => ['class' => ['ai-dashboard-filter-bar__field']]],
        'content_type' => $form['content_type'] + ['#wrapper_attributes' => ['class' => ['ai-dashboard-filter-bar__field']]],
        'participant' => $form['participant'] + ['#wrapper_attributes' => ['class' => ['ai-dashboard-filter-bar__field']]],
      ],
      // The filter bar's own actions: "Apply filters" submits the selected
      // filters, "Clear filters" resets them all.
      'actions' => $form['actions'],
    ];
  }

  /**
   * Build the periods render array.
   *
   * @param string $preset
   *   The period preset.
   *
   * @return array
   *   The render array
   */
  protected function dashboardPeriodControls(string $preset): array {
    return [
      '#type' => 'component',
      '#component' => 'oe_ai_assistant:dashboard-period-tabs',
      '#props' => [
        'items' => [
          [
            'label' => (string) $this->t('Last 30 days'),
            'url' => '?preset=30-days',
            'active' => $preset === '30-days',
          ],
          [
            'label' => (string) $this->t('Last 3 months'),
            'url' => '?preset=3-months',
            'active' => $preset === '3-months',
          ],
          [
            'label' => (string) $this->t('Last 6 months'),
            'url' => '?preset=6-months',
            'active' => $preset === '6-months',
          ],
          ['label' => (string) $this->t('Last year'), 'url' => '?preset=year', 'active' => $preset === 'year'],
          ['label' => (string) $this->t('YTD'), 'url' => '?preset=ytd', 'active' => $preset === 'ytd'],
        ],
      ],
    ];
  }

}
