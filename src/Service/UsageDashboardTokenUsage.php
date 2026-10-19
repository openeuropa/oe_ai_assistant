<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides AI conversation token totals for dashboard periods.
 */
final class UsageDashboardTokenUsage {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the total tokens used in an inclusive date range.
   *
   * Dates must use the Y-m-d format produced by Drupal's date form element.
   * Message timestamps are stored in UTC.
   */
  public function getTokensUsed(string $startDate, string $endDate): int {
    $totalAlias = 'tokens_used';
    $query = $this->entityTypeManager->getStorage('ai_conversation_message')
      ->getAggregateQuery()
      ->accessCheck(FALSE);
    $query->condition('created', [
      $startDate . 'T00:00:00',
      $endDate . 'T23:59:59',
    ], 'BETWEEN');
    $query->aggregate('tokens_total', 'SUM', NULL, $totalAlias);
    $result = $query->execute();

    return (int) ($result[0][$totalAlias] ?? 0);
  }

  /**
   * Returns the cost per token for every month in an inclusive date range.
   *
   * Each result is keyed by YYYY-MM and contains the configured monthly cost,
   * tokens used in the portion of that month covered by the range, and the
   * calculated cost per token. The rate is NULL when the cost is not
   * configured or the period contains no tokens.
   *
   * @return array<string, array{cost: float|null, tokens: int, cost_per_month: float|null}>
   *   The monthly cost and token totals.
   */
  public function getCostPerTokenByMonth(string $startDate, string $endDate): array {
    $timezone = new \DateTimeZone('UTC');
    $start = new \DateTimeImmutable($startDate, $timezone);
    $end = new \DateTimeImmutable($endDate, $timezone);
    $budgets = $this->configFactory->get('oe_ai_assistant.usage_budget')->get('budgets') ?? [];
    $budgets = is_array($budgets) ? $budgets : [];
    $monthlyCosts = [];

    for ($month = $start->modify('first day of this month')->setTime(0, 0); $month <= $end; $month = $month->modify('first day of next month')) {
      $monthStart = max($start, $month);
      $monthEnd = min($end, $month->modify('last day of this month')->setTime(23, 59, 59));
      $year = $month->format('Y');
      $monthNumber = $month->format('m');
      $cost = $budgets[$year][$monthNumber]['cost'] ?? NULL;
      $cost = is_numeric($cost) ? (float) $cost : NULL;
      $tokens = $this->getTokensUsed(
        $monthStart->format('Y-m-d'),
        $monthEnd->format('Y-m-d'),
      );

      $monthlyCosts[$month->format('Y-m')] = [
        'cost' => $cost,
        'tokens' => $tokens,
        'cost_per_month' => $cost !== NULL && $tokens > 0 ? $cost * $tokens : NULL,
      ];
    }

    return $monthlyCosts;
  }

  /**
   * Returns the configured total cost for an inclusive date range.
   *
   * @return float
   *   The sum of the configured monthly costs. Months without a configured
   *   cost contribute zero.
   */
  public function getTotalCost(string $startDate, string $endDate): float {
    $totalCost = 0.0;
    foreach ($this->getCostPerTokenByMonth($startDate, $endDate) as $monthlyCost) {
      $totalCost += $monthlyCost['cost_per_month'] ?? 0.0;
    }

    return $totalCost;
  }

}
