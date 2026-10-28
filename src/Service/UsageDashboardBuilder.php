<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Builds the render arrays for the AI usage dashboard.
 *
 * The dashboard filter form (AiUsageDashboardForm) owns the FAPI elements and
 * submit handling; this service owns the presentation of the dashboard body.
 * Most of the data here is still static prototype content; the live
 * dependencies are the metric cards and the consumption breakdown, which read
 * token totals from UsageDashboardTokenUsage.
 */
final class UsageDashboardBuilder {

  use StringTranslationTrait;

  /**
   * Constructs the builder.
   *
   * @param \Drupal\oe_ai_assistant\Service\UsageDashboardTokenUsage $tokenUsage
   *   The token usage service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to resolve content type labels.
   */
  public function __construct(
    private readonly UsageDashboardTokenUsage $tokenUsage,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds the full dashboard page component.
   *
   * @param array $header
   *   The header render array (compare/export actions).
   * @param array $filters
   *   The filter-bar render array built by the form.
   * @param array $periodControls
   *   The period-tabs render array built by the form.
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters $dataFilters
   *   The content type and participant filters applied to the data queries.
   *
   * @return array
   *   The dashboard page component render array.
   */
  public function build(array $header, array $filters, array $periodControls, string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    return [
      '#type' => 'component',
      '#component' => 'oe_ai_assistant:dashboard-page',
      'header' => $header,
      'filters' => $filters,
      'period_controls' => $periodControls,
      'metrics' => $this->dashboardMetrics($startDate, $endDate, $dataFilters),
      'breakdowns' => $this->dashboardBreakdowns($startDate, $endDate, $dataFilters),
      'supplementary' => $this->dashboardSupplementary($startDate, $endDate, $dataFilters),
    ];
  }

  /**
   * Resolves a named period preset to an inclusive start/end date range.
   *
   * All ranges end today; the named presets are trailing windows ending today
   * except YTD, which starts on the first day of the current year. An unknown
   * preset falls back to the last 30 days. Dates are UTC to match the token
   * usage queries. Shared by the dashboard form and the CSV export controller
   * so both resolve presets identically.
   *
   * @param string $preset
   *   The period preset machine name.
   *
   * @return array{0: string, 1: string}
   *   The inclusive start and end dates in Y-m-d format.
   */
  public static function presetRange(string $preset): array {
    $timezone = new \DateTimeZone('UTC');
    $today = new \DateTimeImmutable('today', $timezone);
    $start = match ($preset) {
      '3-months' => $today->modify('-3 months'),
      '6-months' => $today->modify('-6 months'),
      'year' => $today->modify('-1 year'),
      'ytd' => $today->modify('first day of January this year'),
      default => $today->modify('-30 days'),
    };

    return [$start->format('Y-m-d'), $today->format('Y-m-d')];
  }

  /**
   * Builds a drill-down table view (nodes, major versions, sessions, users).
   *
   * The table views reuse the dashboard chrome (header, filters, period tabs)
   * but replace the overview body with an eyebrow banner and a single data
   * table. The row data is produced by viewTable() so the CSV export can reuse
   * the exact same header and rows.
   *
   * @param string $view
   *   The view machine name: nodes, major-versions, sessions, or users.
   * @param array $header
   *   The header render array (export action).
   * @param array $filters
   *   The filter-bar render array built by the form.
   * @param array $periodControls
   *   The period-tabs render array built by the form.
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters $dataFilters
   *   The content type and participant filters applied to the data queries.
   *
   * @return array
   *   The dashboard page component render array.
   */
  public function buildTable(string $view, array $header, array $filters, array $periodControls, string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    $definition = $this->viewDefinition($view);
    [$headers, $rows] = $this->viewTable($view, $startDate, $endDate, $dataFilters);

    return [
      '#type' => 'component',
      '#component' => 'oe_ai_assistant:dashboard-page',
      '#props' => ['variant' => 'table'],
      'header' => $header,
      'filters' => $filters,
      'period_controls' => $periodControls,
      'metrics' => $this->dashboardComponent('card', [
        'eyebrow' => $definition['eyebrow'],
        'title' => $definition['title'],
        'subtitle' => $definition['subtitle'],
      ]),
      'breakdowns' => $this->dashboardComponent('card', [
        'title' => $definition['table_title'],
        'subtitle' => $definition['table_subtitle'],
      ], [
        'content' => $this->dashboardComponent('data-table', [
          'headers' => $headers,
          'rows' => $rows,
        ]),
      ]),
    ];
  }

  /**
   * Returns the CSV header row and data rows for a drill-down view.
   *
   * The export controller calls this to serialise the same data the table
   * view renders, honouring the active date range and filters.
   *
   * @param string $view
   *   The view machine name: nodes, major-versions, sessions, or users.
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters $dataFilters
   *   The content type and participant filters applied to the data queries.
   *
   * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
   *   The header row and the data rows, each a flat array of cell strings.
   */
  public function viewTable(string $view, string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    return match ($view) {
      'nodes' => $this->nodesTable($startDate, $endDate, $dataFilters),
      'major-versions' => $this->majorVersionsTable($startDate, $endDate, $dataFilters),
      'sessions' => $this->sessionsTable($startDate, $endDate, $dataFilters),
      'users' => $this->usersTable($startDate, $endDate, $dataFilters),
      default => [[], []],
    };
  }

  /**
   * Returns the banner labels for a drill-down view.
   *
   * @param string $view
   *   The view machine name.
   *
   * @return array{eyebrow: string, title: string, subtitle: string, table_title: string, table_subtitle: string}
   *   The banner and table labels.
   */
  private function viewDefinition(string $view): array {
    return match ($view) {
      'major-versions' => [
        'eyebrow' => (string) $this->t('Major version view'),
        'title' => (string) $this->t('Major versions per node'),
        'subtitle' => (string) $this->t('For each node edited with AI, you can see major versions with their token usage and drill down further.'),
        'table_title' => (string) $this->t('Major versions'),
        'table_subtitle' => (string) $this->t('Major version number, node title, node ID, content type, participant, template used, total sessions, total tokens.'),
      ],
      'sessions' => [
        'eyebrow' => (string) $this->t('Session view'),
        'title' => (string) $this->t('Sessions per major version'),
        'subtitle' => (string) $this->t('For each major version, drill down to all sessions with session ID and token usage.'),
        'table_title' => (string) $this->t('Sessions'),
        'table_subtitle' => (string) $this->t('Session ID, major version number, node title, node ID, content type, participant, template used, total tokens.'),
      ],
      'users' => [
        'eyebrow' => (string) $this->t('User view'),
        'title' => (string) $this->t('Tokens per user'),
        'subtitle' => (string) $this->t('Sitewide list of users with aggregated tokens for sessions they authored.'),
        'table_title' => (string) $this->t('Tokens per user'),
        'table_subtitle' => (string) $this->t('Username, number of nodes edited with AI, number of sessions, total tokens.'),
      ],
      default => [
        'eyebrow' => (string) $this->t('Node view'),
        'title' => (string) $this->t('List of nodes supported with AI'),
        'subtitle' => (string) $this->t('Ordered by total token usage over time and filterable by content type, participant and template used.'),
        'table_title' => (string) $this->t('Nodes'),
        'table_subtitle' => (string) $this->t('Node title, node ID, content type, participant, template used, major versions, total sessions, total tokens.'),
      ],
    };
  }

  /**
   * Builds the nodes drill-down table.
   */
  private function nodesTable(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    $headers = [
      (string) $this->t('Node'),
      (string) $this->t('ID'),
      (string) $this->t('Type'),
      (string) $this->t('Participant'),
      (string) $this->t('Template'),
      (string) $this->t('Majors'),
      (string) $this->t('Sessions'),
      (string) $this->t('Tokens'),
    ];
    $bundleLabels = $this->bundleLabels();
    $userLabels = $this->userLabels();
    $rows = [];
    foreach ($this->tokenUsage->getNodeRows($startDate, $endDate, $dataFilters) as $row) {
      $rows[] = [
        $row['label'],
        '#' . $row['node_id'],
        $bundleLabels[$row['bundle']] ?? $row['bundle'],
        $row['uid'] !== NULL ? ($userLabels[$row['uid']] ?? (string) $row['uid']) : '-',
        $this->yesNo($row['template']),
        (string) $row['majors'],
        (string) $row['sessions'],
        number_format($row['tokens']),
      ];
    }

    return [$headers, $rows];
  }

  /**
   * Builds the major-versions drill-down table.
   */
  private function majorVersionsTable(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    $headers = [
      (string) $this->t('Version'),
      (string) $this->t('Node'),
      (string) $this->t('ID'),
      (string) $this->t('Type'),
      (string) $this->t('Participant'),
      (string) $this->t('Template'),
      (string) $this->t('Sessions'),
      (string) $this->t('Tokens'),
    ];
    $bundleLabels = $this->bundleLabels();
    $userLabels = $this->userLabels();
    $rows = [];
    foreach ($this->tokenUsage->getMajorVersionRows($startDate, $endDate, $dataFilters) as $row) {
      $rows[] = [
        'v' . $row['major'],
        $row['label'],
        '#' . $row['node_id'],
        $bundleLabels[$row['bundle']] ?? $row['bundle'],
        $row['uid'] !== NULL ? ($userLabels[$row['uid']] ?? (string) $row['uid']) : '-',
        $this->yesNo($row['template']),
        (string) $row['sessions'],
        number_format($row['tokens']),
      ];
    }

    return [$headers, $rows];
  }

  /**
   * Builds the sessions drill-down table.
   */
  private function sessionsTable(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    $headers = [
      (string) $this->t('Session ID'),
      (string) $this->t('Version'),
      (string) $this->t('Node'),
      (string) $this->t('ID'),
      (string) $this->t('Type'),
      (string) $this->t('Participant'),
      (string) $this->t('Template'),
      (string) $this->t('Tokens'),
    ];
    $bundleLabels = $this->bundleLabels();
    $userLabels = $this->userLabels();
    $rows = [];
    foreach ($this->tokenUsage->getSessionRows($startDate, $endDate, $dataFilters) as $row) {
      $rows[] = [
        'S-' . $row['session_id'],
        $row['major'] !== NULL ? 'v' . $row['major'] : '-',
        $row['label'],
        '#' . $row['node_id'],
        $bundleLabels[$row['bundle']] ?? $row['bundle'],
        $row['uid'] !== NULL ? ($userLabels[$row['uid']] ?? (string) $row['uid']) : '-',
        $this->yesNo($row['template']),
        number_format($row['tokens']),
      ];
    }

    return [$headers, $rows];
  }

  /**
   * Builds the cost-per-user drill-down table.
   */
  private function usersTable(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    $headers = [
      (string) $this->t('Username'),
      (string) $this->t('Nodes edited with AI'),
      (string) $this->t('Sessions'),
      (string) $this->t('Total tokens'),
    ];
    $userLabels = $this->userLabels();
    $rows = [];
    foreach ($this->tokenUsage->getUserRows($startDate, $endDate, $dataFilters) as $row) {
      $rows[] = [
        $userLabels[$row['uid']] ?? (string) $row['uid'],
        (string) $row['nodes'],
        (string) $row['sessions'],
        number_format($row['tokens']),
      ];
    }

    return [$headers, $rows];
  }

  /**
   * Returns node bundle labels keyed by machine name.
   *
   * @return array<string, string>
   *   The content type labels.
   */
  private function bundleLabels(): array {
    $labels = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $id => $type) {
      $labels[(string) $id] = (string) $type->label();
    }

    return $labels;
  }

  /**
   * Returns user labels keyed by user ID.
   *
   * @return array<int, string>
   *   The user labels.
   */
  private function userLabels(): array {
    $labels = [];
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple() as $uid => $user) {
      $labels[(int) $uid] = (string) $user->label();
    }

    return $labels;
  }

  /**
   * Formats a boolean template flag as a Yes/No cell.
   *
   * @param bool $value
   *   The template-usage flag.
   *
   * @return string
   *   The localised Yes or No label.
   */
  private function yesNo(bool $value): string {
    return $value ? (string) $this->t('Yes') : (string) $this->t('No');
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
   * Builds the metrics render array.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters $dataFilters
   *   The content type and participant filters applied to the data queries.
   *
   * @return array
   *   The render array.
   */
  private function dashboardMetrics(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    $tokensPerSession = $this->tokenUsage->getAverageTokensPerSession($startDate, $endDate, $dataFilters);
    $tokensPerVersion = $this->tokenUsage->getAverageTokensPerMajorVersion($startDate, $endDate, $dataFilters);
    return [
      'tokens_consumed' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('Tokens consumed'),
        'value' => number_format($this->tokenUsage->getTokensUsed($startDate, $endDate, $dataFilters)),
        'trend' => '',
        'trend_state' => 'positive',
      ]),
      'supported_nodes' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('AI supported nodes'),
        'value' => number_format($this->tokenUsage->countSupportedNodes($startDate, $endDate, $dataFilters)),
        'trend' => '',
        'trend_state' => 'positive',
      ]),
      'cost_per_session' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('Average tokens used per session'),
        'value' => number_format($tokensPerSession, 2),
        'trend' => '',
        'trend_state' => 'negative',
      ]),
      'cost_per_version' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('Average tokens used per major version'),
        'value' => number_format($tokensPerVersion, 2),
        'trend' => '',
        'trend_state' => 'positive',
      ]),
    ];
  }

  /**
   * Builds ranked-bar items for token usage by node content type.
   *
   * The bar width (percent) is scaled against the highest content type so the
   * top category fills the track; the value is the formatted token count.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters $dataFilters
   *   The content type and participant filters applied to the data queries.
   *
   * @return array<int, array{label: string, percent: int, value: string}>
   *   The ranked-bar items, highest token usage first.
   */
  private function contentTypeTokenItems(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    $tokensByType = $this->tokenUsage->getTokensUsedByContentType($startDate, $endDate, $dataFilters);
    if ($tokensByType === []) {
      return [];
    }

    // Results are already sorted descending, so the first value is the max.
    $max = (int) reset($tokensByType);
    $typeStorage = $this->entityTypeManager->getStorage('node_type');
    $items = [];
    foreach ($tokensByType as $bundle => $tokens) {
      $type = $typeStorage->load($bundle);
      $items[] = [
        'label' => (string) ($type?->label() ?? $bundle),
        'percent' => $max > 0 ? (int) round($tokens / $max * 100) : 0,
        'value' => number_format($tokens),
      ];
    }

    return $items;
  }

  /**
   * Builds ranked-bar items for token usage by author user.
   *
   * The bar width (percent) is scaled against the highest user so the top
   * user fills the track; the value is the formatted token count.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters $dataFilters
   *   The content type and participant filters applied to the data queries.
   *
   * @return array<int, array{label: string, percent: int, value: string}>
   *   The ranked-bar items, highest token usage first.
   */
  private function userTokenItems(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    $tokensByUser = $this->tokenUsage->getTokensUsedByUser($startDate, $endDate, $dataFilters);
    if ($tokensByUser === []) {
      return [];
    }

    // Results are already sorted descending, so the first value is the max.
    $max = (int) reset($tokensByUser);
    $userStorage = $this->entityTypeManager->getStorage('user');
    $users = $userStorage->loadMultiple(array_keys($tokensByUser));
    $items = [];
    foreach ($tokensByUser as $uid => $tokens) {
      $user = $users[$uid] ?? NULL;
      $items[] = [
        'label' => (string) ($user?->getDisplayName() ?? $uid),
        'percent' => $max > 0 ? (int) round($tokens / $max * 100) : 0,
        'value' => number_format($tokens),
      ];
    }

    return $items;
  }

  /**
   * Builds the consumption and user breakdown render array.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters $dataFilters
   *   The content type and participant filters applied to the data queries.
   *
   * @return array
   *   The render array.
   */
  private function dashboardBreakdowns(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    return [
      'consumption' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Consumption breakdown'),
        'subtitle' => (string) $this->t('Tokens consumed by content type.'),
      ], [
        'content' => [
          'ranked' => $this->dashboardComponent('ranked-bars', [
            'items' => $this->contentTypeTokenItems($startDate, $endDate, $dataFilters),
          ]),
        ],
      ]),
      'users' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Users'),
        'subtitle' => (string) $this->t('Tokens consumed by user.'),
      ], [
        'content' => $this->dashboardComponent('ranked-bars', [
          'items' => $this->userTokenItems($startDate, $endDate, $dataFilters),
        ]),
      ]),
    ];
  }

  /**
   * Builds list-card items for token usage by drafting template.
   *
   * Each item shows the template label, its distinct session count as the
   * detail line, and the formatted token count as the value.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters $dataFilters
   *   The content type and participant filters applied to the data queries.
   *
   * @return array<int, array{title: string, detail: string, value: string}>
   *   The list-card items, highest token usage first.
   */
  private function templateTokenItems(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    $tokensByTemplate = $this->tokenUsage->getTokensUsedByTemplate($startDate, $endDate, $dataFilters);
    if ($tokensByTemplate === []) {
      return [];
    }

    $sessionsByTemplate = $this->tokenUsage->countSessionsByTemplate($startDate, $endDate, $dataFilters);
    $templateStorage = $this->entityTypeManager->getStorage('ai_drafting_template');
    $templates = $templateStorage->loadMultiple(array_keys($tokensByTemplate));
    $items = [];
    foreach ($tokensByTemplate as $templateId => $tokens) {
      $template = $templates[$templateId] ?? NULL;
      $sessions = $sessionsByTemplate[$templateId] ?? 0;
      $items[] = [
        'title' => (string) ($template?->label() ?? $templateId),
        'detail' => (string) $this->formatPlural($sessions, '1 session', '@count sessions'),
        'value' => number_format($tokens),
      ];
    }

    return $items;
  }

  /**
   * Builds the supplementary-widget render array.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters $dataFilters
   *   The content type and participant filters applied to the data queries.
   *
   * @return array
   *   The render array.
   */
  private function dashboardSupplementary(string $startDate, string $endDate, DashboardFilters $dataFilters): array {
    return [
      'templates' => $this->dashboardComponent('list-card', [
        'title' => (string) $this->t('Top templates'),
        'subtitle' => (string) $this->t('Tokens consumed by template.'),
        'items' => $this->templateTokenItems($startDate, $endDate, $dataFilters),
      ]),
    ];
  }

}
