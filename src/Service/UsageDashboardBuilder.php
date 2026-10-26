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
   *
   * @return array
   *   The dashboard page component render array.
   */
  public function build(array $header, array $filters, array $periodControls, string $startDate, string $endDate): array {
    return [
      '#type' => 'component',
      '#component' => 'oe_ai_assistant:dashboard-page',
      'header' => $header,
      'filters' => $filters,
      'period_controls' => $periodControls,
      'metrics' => $this->dashboardMetrics($startDate, $endDate),
      'breakdowns' => $this->dashboardBreakdowns($startDate, $endDate),
      'supplementary' => $this->dashboardSupplementary($startDate, $endDate),
    ];
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
   *
   * @return array
   *   The render array.
   */
  private function dashboardMetrics(string $startDate, string $endDate): array {
    $tokensPerSession = $this->tokenUsage->getAverageTokensPerSession($startDate, $endDate);
    $tokensPerVersion = $this->tokenUsage->getAverageTokensPerMajorVersion($startDate, $endDate);
    return [
      'tokens_consumed' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('Tokens consumed'),
        'value' => number_format($this->tokenUsage->getTokensUsed($startDate, $endDate)),
        'trend' => (string) $this->t('+18.4% vs previous month'),
        'trend_state' => 'positive',
      ]),
      'supported_nodes' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('AI supported nodes'),
        'value' => number_format($this->tokenUsage->countSupportedNodes($startDate, $endDate)),
        'trend' => (string) $this->t('30-day cumulative trend, +22 nodes'),
        'trend_state' => 'positive',
      ]),
      'cost_per_session' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('Average tokens used per session'),
        'value' => number_format($tokensPerSession, 2),
        'trend' => (string) $this->t('-7% vs last 30 days'),
        'trend_state' => 'negative',
      ]),
      'cost_per_version' => $this->dashboardComponent('metric-card', [
        'label' => (string) $this->t('Average tokens used per major version'),
        'value' => number_format($tokensPerVersion, 2),
        'trend' => (string) $this->t('+4.3% with 96 AI-supported major versions'),
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
   *
   * @return array<int, array{label: string, percent: int, value: string}>
   *   The ranked-bar items, highest token usage first.
   */
  private function contentTypeTokenItems(string $startDate, string $endDate): array {
    $tokensByType = $this->tokenUsage->getTokensUsedByContentType($startDate, $endDate);
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
   *
   * @return array<int, array{label: string, percent: int, value: string}>
   *   The ranked-bar items, highest token usage first.
   */
  private function userTokenItems(string $startDate, string $endDate): array {
    $tokensByUser = $this->tokenUsage->getTokensUsedByUser($startDate, $endDate);
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
   *
   * @return array
   *   The render array.
   */
  private function dashboardBreakdowns(string $startDate, string $endDate): array {
    return [
      'consumption' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Consumption breakdown'),
        'subtitle' => (string) $this->t('Top token categories, users, and AI session types.'),
      ], [
        'content' => [
          'ranked' => $this->dashboardComponent('ranked-bars', [
            'items' => $this->contentTypeTokenItems($startDate, $endDate),
          ]),
        ],
      ]),
      'users' => $this->dashboardComponent('card', [
        'title' => (string) $this->t('Users'),
        'subtitle' => (string) $this->t('Top tokens used and total sessions by user for the selected date range.'),
      ], [
        'content' => $this->dashboardComponent('ranked-bars', [
          'items' => $this->userTokenItems($startDate, $endDate),
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
   *
   * @return array<int, array{title: string, detail: string, value: string}>
   *   The list-card items, highest token usage first.
   */
  private function templateTokenItems(string $startDate, string $endDate): array {
    $tokensByTemplate = $this->tokenUsage->getTokensUsedByTemplate($startDate, $endDate);
    if ($tokensByTemplate === []) {
      return [];
    }

    $sessionsByTemplate = $this->tokenUsage->countSessionsByTemplate($startDate, $endDate);
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
   *
   * @return array
   *   The render array.
   */
  private function dashboardSupplementary(string $startDate, string $endDate): array {
    return [
      'templates' => $this->dashboardComponent('list-card', [
        'title' => (string) $this->t('Top templates'),
        'subtitle' => (string) $this->t('Optional view retained as a secondary widget.'),
        'items' => $this->templateTokenItems($startDate, $endDate),
      ]),
    ];
  }

}
