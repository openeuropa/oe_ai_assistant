<?php

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\oe_ai_assistant\Service\UsageDashboardTokenUsage;
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
   * @param \Drupal\oe_ai_assistant\Service\UsageDashboardTokenUsage $tokenUsage
   *   The token usage service.
   */
  public function __construct(
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $dashboardRequestStack,
    protected UsageDashboardTokenUsage $tokenUsage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get(UsageDashboardTokenUsage::class),
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
      '#title' => $this->t('Content types'),
      '#options' => $contentTypes,
      '#empty_option' => $this->t('All content types'),
      '#default_value' => $selectedContentType,
    ];
    $form['participant'] = [
      '#type' => 'select',
      '#title' => $this->t('Users'),
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

    $form['dashboard'] = [
      '#type' => 'component',
      '#component' => 'oe_ai_assistant:dashboard-page',
      'header' => [
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
          'export' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Export CSV'),
            '#attributes' => ['class' => ['button'], 'type' => 'button'],
          ],
        ],
      ],
      'filters' => $this->dashboardFilters($form),
      'period_controls' => $this->dashboardPeriodControls($preset),
      'forecast' => $this->dashboardForecast($startDate, $endDate),
      'metrics' => $this->dashboardMetrics(),
      'breakdowns' => $this->dashboardBreakdowns(),
      'trends' => $this->dashboardTrends(),
      'review' => $this->dashboardReview(),
      'quality' => $this->dashboardQuality(),
      'activity' => $this->dashboardActivity(),
      'supplementary' => $this->dashboardSupplementary(),
    ];
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
   * Builds a dashboard component render array.
   *
   * @param string $component
   *   The component machine name without the module prefix.
   * @param array $props
   *   The component props.
   * @param array $slots
   *   The component slots.
   *
   * @return array
   *   The component render array.
   */
  private function dashboardComponent(string $component, array $props = [], array $slots = []): array {
    return [
      '#type' => 'component',
      '#component' => "oe_ai_assistant:dashboard-$component",
      '#props' => $props,
    ] + $slots;
  }

  /**
   * Builds a decorative line chart for the dashboard prototype.
   *
   * @param string $color
   *   The chart line colour.
   *
   * @return array
   *   The chart render array.
   */
  private function lineChart(string $color): array {
    return [
      '#markup' => '<svg class="ai-dashboard-chart" viewBox="0 0 640 280" role="img" aria-label="Trend chart"><path class="ai-dashboard-chart__grid" d="M20 40H620M20 100H620M20 160H620M20 220H620"/><path class="ai-dashboard-chart__line" stroke="' . $color . '" d="M25 210 L110 190 L195 175 L280 150 L365 120 L450 85 L535 60 L615 25"/></svg>',
    ];
  }

  /**
   * Builds the session-type donut chart placeholder.
   *
   * @return array
   *   The chart render array.
   */
  private function donutChart(): array {
    return [
      '#markup' => '<div class="ai-dashboard-donut-chart" role="img" aria-label="57 percent page creation and 43 percent page review"><strong>2,184</strong><span>sessions</span></div>',
    ];
  }

  /**
   * Builds the review-action summary table.
   *
   * @return array
   *   The table component render array.
   */
  private function reviewActionsTable(): array {
    return $this->dashboardComponent('data-table', [
      'caption' => (string) $this->t('Page review actions'),
      'headers' => [
        (string) $this->t('Category'),
        (string) $this->t('Consumption'),
        (string) $this->t('Pending / total'),
        (string) $this->t('Pending cost'),
        (string) $this->t('Avg. estimated cost'),
      ],
      'rows' => [
        [(string) $this->t('Readability'), '€620.20', '74 / 211', '€182.40', '€2.94'],
        [(string) $this->t('SEO metadata'), '€488.70', '55 / 173', '€139.80', '€2.82'],
        [(string) $this->t('Accessibility'), '€352.10', '38 / 118', '€95.70', '€2.81'],
        [(string) $this->t('Legal/style'), '€410.80', '67 / 154', '€213.70', '€3.34'],
        [(string) $this->t('Plain language'), '€531.40', '52 / 149', '€178.80', '€3.57'],
      ],
    ]);
  }

  /**
   * Builds the review-priority table.
   *
   * @return array
   *   The table component render array.
   */
  private function priorityMixTable(): array {
    return $this->dashboardComponent('data-table', [
      'caption' => (string) $this->t('Priority mix by review category'),
      'headers' => [
        (string) $this->t('Category'),
        (string) $this->t('Critical'),
        (string) $this->t('Medium'),
        (string) $this->t('Low'),
      ],
      'rows' => [
        [(string) $this->t('Readability'), '21', '31', '22'],
        [(string) $this->t('SEO metadata'), '14', '23', '18'],
        [(string) $this->t('Accessibility'), '11', '16', '11'],
        [(string) $this->t('Legal/style'), '24', '28', '15'],
        [(string) $this->t('Plain language'), '18', '20', '14'],
      ],
    ]);
  }

  /**
   * Builds the expensive-node table.
   *
   * @return array
   *   The table component render array.
   */
  private function expensiveNodesTable(): array {
    return $this->dashboardComponent('data-table', [
      'caption' => (string) $this->t('Most expensive nodes'),
      'headers' => [
        (string) $this->t('Node'),
        (string) $this->t('ID'),
        (string) $this->t('Type'),
        (string) $this->t('Author'),
        (string) $this->t('Major versions'),
        (string) $this->t('Sessions'),
        (string) $this->t('Cost'),
      ],
      'rows' => [
        [
          (string) $this->t('EU climate funding 2026'), '#8124',
          (string) $this->t('Policy page'), (string) $this->t('User 1'),
          '8', '41', '€286.30',
        ],
        [
          (string) $this->t('Blue economy call'), '#7719',
          (string) $this->t('News'), (string) $this->t('User 2'),
          '6', '37', '€241.95',
        ],
        [
          (string) $this->t('Digital Europe overview'), '#7466',
          (string) $this->t('Landing page'), (string) $this->t('User 3'),
          '11', '54', '€223.10',
        ],
        [
          (string) $this->t('Horizon Europe missions'), '#7195',
          (string) $this->t('Project'), (string) $this->t('User 4'),
          '5', '29', '€198.75',
        ],
        [
          (string) $this->t('Regional innovation hubs'), '#7002',
          (string) $this->t('Call page'), (string) $this->t('User 6'),
          '4', '23', '€182.40',
        ],
      ],
    ]);
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
   * Build the filters render array.
   *
   * @param array $form
   *   The complete form array.
   *
   * @return array
   *   The render array
   */
  public function dashboardFilters(array $form): array {
    return [
      '#type' => 'component',
      '#component' => 'oe_ai_assistant:dashboard-filter-bar',
      'filters' => [
        '#type' => 'container',
        'date_range' => [
          '#type' => 'fieldset',
          '#title' => $this->t('Date range'),
          '#attributes' => ['class' => ['ai-dashboard-filter-bar__date-range']],
          'start' => $form['start'] + ['#title_display' => 'invisible'],
          'end' => $form['end'] + ['#title_display' => 'invisible'],
        ],
        'session_type' => $form['session_type'],
        'content_type' => $form['content_type'],
        'participant' => $form['participant'],
      ],
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
  public function dashboardPeriodControls(string $preset): array {
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

  /**
   * Build the forecast render array.
   *
   * @return array
   *   The render array
   */
  public function dashboardForecast(string $startDate, string $endDate): array {
    return [
      '#type' => 'component',
      '#component' => 'oe_ai_assistant:dashboard-forecast-card',
      '#props' => [
        'eyebrow' => (string) $this->t('Monthly billing control'),
        'heading' => (string) $this->t('July spend is above the monthly forecast'),
        'description' => (string) $this->t('The forecast is now a first-class element of the overview, so site owners can set a monthly target, monitor month-to-date consumption, and immediately see whether the current month is on track or overrun.'),
        'spent' => (string) $this->tokenUsage->get($startDate, $endDate),
        'spent_trend' => (string) $this->t('+18.4% vs previous month'),
        'forecast' => '€4,200',
        'forecast_status' => (string) $this->t('Exceeded by €660'),
        'progress' => 116,
        'usage_summary' => (string) $this->t('€4,860 spent of €4,200 forecast · 116% used · projected close at €5,310'),
        'billing_cycle' => (string) $this->t('Resets on 31 Jul 2026'),
      ],
      'action' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Edit forecast'),
        '#attributes' => [
          'class' => ['button'],
          'type' => 'button',
        ],
      ],
    ];
  }

  /**
   * Build the metrics render array.
   *
   * @return array
   *   The render array
   */
  public function dashboardMetrics(): array {
    return [
      'supported_nodes' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('AI supported nodes'),
        'value' => '184',
        'trend' => (string) $this->t('30-day cumulative trend, +22 nodes'),
        'trend_state' => 'positive',
      ]),
      'cost_per_session' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('Average cost per session'),
        'value' => '€2.23',
        'trend' => (string) $this->t('-7% vs last 30 days'),
        'trend_state' => 'negative',
      ]),
      'pending_cost' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('Pending AI suggestion cost'),
        'value' => '€809',
        'trend' => (string) $this->t('286 pending of 805 total suggestions'),
        'trend_state' => 'neutral',
      ]),
      'cost_per_version' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('Average cost per major version'),
        'value' => '€31.84',
        'trend' => (string) $this->t('+4.3% with 96 AI-supported major versions'),
        'trend_state' => 'positive',
      ]),
    ];
  }

  /**
   * Builds the consumption and user breakdown render array.
   */
  public function dashboardBreakdowns(): array {
    return [
      'consumption' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Consumption breakdown'),
        'subtitle' => (string) $this->t('Top spend categories, users, and AI session types.'),
      ], [
        'content' => [
          'ranked' => $this->dashboardComponent('ranked-bars', [
            'items' => [
              ['label' => (string) $this->t('Policy page'), 'percent' => 92, 'value' => '€1,240'],
              ['label' => (string) $this->t('Landing page'), 'percent' => 80, 'value' => '€1,090'],
              ['label' => (string) $this->t('News'), 'percent' => 74, 'value' => '€980'],
              ['label' => (string) $this->t('Project'), 'percent' => 62, 'value' => '€780'],
              ['label' => (string) $this->t('Call page'), 'percent' => 52, 'value' => '€650'],
            ],
          ]),
          'session_types' => $this->dashboardComponent('donut-summary', [
            'total' => '2,184',
            'label' => (string) $this->t('Total tokens used'),
            'items' => [
              ['label' => (string) $this->t('Page creation'), 'value' => '57%'],
              ['label' => (string) $this->t('Page review'), 'value' => '43%'],
            ],
          ], ['chart' => $this->donutChart()]),
        ],
      ]),
      'users' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Users'),
        'subtitle' => (string) $this->t('Top spend and total sessions by user for the selected date range.'),
      ], [
        'content' => $this->dashboardComponent('ranked-bars', [
          'items' => [
            ['label' => (string) $this->t('User 3'), 'percent' => 92, 'value' => '€1,380'],
            ['label' => (string) $this->t('User 1'), 'percent' => 80, 'value' => '€1,220'],
            ['label' => (string) $this->t('User 4'), 'percent' => 64, 'value' => '€970'],
            ['label' => (string) $this->t('User 2'), 'percent' => 58, 'value' => '€880'],
            ['label' => (string) $this->t('User 6'), 'percent' => 38, 'value' => '€550'],
          ],
        ]),
      ]),
    ];
  }

  /**
   * Builds the consumption trend render array.
   */
  public function dashboardTrends(): array {
    return [
      'consumption' => $this->dashboardComponent('chart-card', [
        'title' => (string) $this->t('Consumption trend by action/session type'),
        'subtitle' => (string) $this->t('Timeline depends on the dashboard date range filter.'),
      ], ['chart' => $this->lineChart('#087d78')]),
      'cost' => $this->dashboardComponent('chart-card', [
        'title' => (string) $this->t('Average token cost per major version'),
        'subtitle' => (string) $this->t('Trend line showing average cost per major version.'),
      ], ['chart' => $this->lineChart('#3f7d38')]),
    ];
  }

  /**
   * Builds the page-review render array.
   */
  public function dashboardReview(): array {
    return [
      'actions' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Page review actions'),
        'subtitle' => (string) $this->t('Each category includes pending ratio, pending cost, and estimated average cost.'),
      ], ['content' => $this->reviewActionsTable()]),
      'backlog' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Backlog focus'),
        'subtitle' => (string) $this->t('Nodes with the lowest page-review scores that need attention first.'),
      ], [
        'content' => $this->dashboardComponent('backlog-list', [
          'items' => [
            [
              'title' => (string) $this->t('Legal/style'),
              'detail' => (string) $this->t('67 pending suggestions'),
              'status' => (string) $this->t('Highest unresolved'),
              'value' => '€213.70',
            ],
            [
              'title' => (string) $this->t('Readability'),
              'detail' => (string) $this->t('74 pending suggestions'),
              'status' => (string) $this->t('Watchlist'),
              'value' => '€182.40',
            ],
            [
              'title' => (string) $this->t('Plain language'),
              'detail' => (string) $this->t('52 pending suggestions'),
              'status' => (string) $this->t('Large volume'),
              'value' => '€178.80',
            ],
          ],
        ]),
      ]),
    ];
  }

  /**
   * Builds the content-quality render array.
   */
  public function dashboardQuality(): array {
    return [
      'score' => $this->dashboardComponent('chart-card', [
        'title' => (string) $this->t('Sitewide content quality section – page review score'),
        'subtitle' => (string) $this->t('Coverage, priority mix, and score trend for AI-reviewed pages.'),
      ], [
        'chart' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-dashboard-quality-summary']],
          'score' => $this->dashboardComponent('metric-card', [
            'label' => (string) $this->t('Average page review score'),
            'value' => '71',
            'trend' => (string) $this->t('Across pages reviewed by AI'),
            'trend_state' => 'positive',
          ]),
          'coverage' => $this->dashboardComponent('metric-card', [
            'label' => (string) $this->t('Scored pages coverage'),
            'value' => '64%',
            'trend' => (string) $this->t('428 scored pages of 672 total pages'),
            'trend_state' => 'neutral',
          ]),
          'trend' => $this->lineChart('#3f7d38'),
        ],
      ]),
      'priority' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Priority mix by review category'),
        'subtitle' => (string) $this->t('Pending critical, medium, and low-priority suggestions for each review category.'),
      ], ['content' => $this->priorityMixTable()]),
    ];
  }

  /**
   * Builds the expensive-node and session render array.
   */
  public function dashboardActivity(): array {
    return [
      'nodes' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Top 5 most expensive nodes'),
        'subtitle' => (string) $this->t('Includes the fields most used in the selected time range.'),
      ], ['content' => $this->expensiveNodesTable()]),
      'sessions' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Top sessions'),
        'subtitle' => (string) $this->t('Five highest-cost sessions with template and tone context.'),
      ], [
        'content' => $this->dashboardComponent('session-list', [
          'items' => [
            [
              'title' => 'S-12048 · ' . (string) $this->t('Review audit'),
              'meta' => (string) $this->t('Participant: User 1 · Template: Yes · Tone: Citizen focused'),
              'value' => '€10.60',
            ],
            [
              'title' => 'S-18411 · ' . (string) $this->t('Create/edit'),
              'meta' => (string) $this->t('Participant: User 3 · Template: No · Tone: Engaging'),
              'value' => '€8.80',
            ],
            [
              'title' => 'S-12041 · ' . (string) $this->t('Create/edit'),
              'meta' => (string) $this->t('Participant: User 1 · Template: Yes · Tone: Citizen focused'),
              'value' => '€8.10',
            ],
            [
              'title' => 'S-09122 · ' . (string) $this->t('Other'),
              'meta' => (string) $this->t('Participant: User 2 · Template: Yes · Tone: EC default'),
              'value' => '€7.40',
            ],
          ],
        ]),
      ]),
    ];
  }

  /**
   * Builds the supplementary-widget render array.
   */
  public function dashboardSupplementary(): array {
    return [
      'templates' => $this->dashboardComponent('list-card', [
        'title' => (string) $this->t('Top templates'),
        'subtitle' => (string) $this->t('Optional view retained as a secondary widget.'),
        'items' => [
          [
            'title' => (string) $this->t('Policy page · Plain language'),
            'detail' => (string) $this->t('62 sessions'),
            'value' => '€402.10',
          ],
          [
            'title' => (string) $this->t('News · Metadata'),
            'detail' => (string) $this->t('49 sessions'),
            'value' => '€318.40',
          ],
          [
            'title' => (string) $this->t('Landing page · Legal review'),
            'detail' => (string) $this->t('31 sessions'),
            'value' => '€284.50',
          ],
        ],
      ]),
      'feedback' => $this->dashboardComponent('list-card', [
        'title' => (string) $this->t('Feedback list'),
        'subtitle' => (string) $this->t('Optional list for retained short-ready patterns.'),
        'items' => [
          [
            'title' => 'Audit #A-611',
            'detail' => (string) $this->t('Rejected page review · 26 Jul 2026'),
            'value' => '€6.40',
          ],
          [
            'title' => 'Audit #A-602',
            'detail' => (string) $this->t('Very helpful · 26 Jul 2026'),
            'value' => '€5.90',
          ],
          [
            'title' => 'Audit #A-589',
            'detail' => (string) $this->t('Partly helpful · 24 Jul 2026'),
            'value' => '€5.20',
          ],
        ],
      ]),
    ];
  }

}
