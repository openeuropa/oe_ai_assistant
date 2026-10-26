<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryAggregateInterface;

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
  public function getTokensUsed(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): int {
    $totalAlias = 'tokens_used';
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->aggregate('tokens_total', 'SUM', NULL, $totalAlias);
    $result = $query->execute();
    return (int) ($result[0][$totalAlias] ?? 0);
  }

  /**
   * Returns the number of distinct sessions with provenance in a date range.
   */
  public function countSessions(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): int {
    return $this->countDistinct('session', $startDate, $endDate, $filters);
  }

  /**
   * Returns the number of distinct major versions with provenance in a range.
   */
  public function countMajorVersions(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): int {
    return $this->countDistinct('version_major', $startDate, $endDate, $filters);
  }

  /**
   * Returns the number of distinct AI-supported nodes in a date range.
   *
   * A node is AI-supported when it owns at least one provenance record in the
   * range; each distinct node is counted once.
   */
  public function countSupportedNodes(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): int {
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->condition('entity_type', 'node');
    $query->exists('entity_id');
    $query->groupBy('entity_id');
    return count($query->execute());
  }

  /**
   * Returns total tokens used per node content type in a date range.
   *
   * Provenance records store the node ID but not its bundle, so the tokens are
   * first summed per node and then regrouped by the node's content type. The
   * result is keyed by content type machine name and sorted by tokens used,
   * descending. Node IDs without a loadable node are skipped.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<string, int>
   *   Tokens used keyed by content type machine name, highest first.
   */
  public function getTokensUsedByContentType(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): array {
    $tokensAlias = 'tokens_used';
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->condition('entity_type', 'node');
    $query->exists('entity_id');
    $query->aggregate('tokens_total', 'SUM', NULL, $tokensAlias);
    $query->groupBy('entity_id');
    $rows = $query->execute();

    // Map each node ID to its summed tokens, then fold into bundle totals.
    $tokensByNode = [];
    foreach ($rows as $row) {
      $tokensByNode[(int) $row['entity_id']] = (int) ($row[$tokensAlias] ?? 0);
    }
    if ($tokensByNode === []) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $tokensByType = [];
    foreach ($nodeStorage->loadMultiple(array_keys($tokensByNode)) as $nodeId => $node) {
      $bundle = $node->bundle();
      $tokensByType[$bundle] = ($tokensByType[$bundle] ?? 0) + $tokensByNode[(int) $nodeId];
    }

    arsort($tokensByType);
    return $tokensByType;
  }

  /**
   * Returns total tokens used per author user in an inclusive date range.
   *
   * Provenance records store the author directly, so tokens are summed per
   * uid in a single aggregate query. The result is keyed by user ID and
   * sorted by tokens used, descending. Records without an author are skipped.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<int, int>
   *   Tokens used keyed by user ID, highest first.
   */
  public function getTokensUsedByUser(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): array {
    $tokensAlias = 'tokens_used';
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->exists('uid');
    $query->aggregate('tokens_total', 'SUM', NULL, $tokensAlias);
    $query->groupBy('uid');
    $rows = $query->execute();

    $tokensByUser = [];
    foreach ($rows as $row) {
      $tokensByUser[(int) $row['uid']] = (int) ($row[$tokensAlias] ?? 0);
    }

    arsort($tokensByUser);
    return $tokensByUser;
  }

  /**
   * Returns total tokens used per drafting template in a date range.
   *
   * Provenance records reference the template directly, so tokens are summed
   * per template in a single aggregate query. The result is keyed by template
   * ID and sorted by tokens used, descending. Records without a template are
   * skipped.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<string, int>
   *   Tokens used keyed by template ID, highest first.
   */
  public function getTokensUsedByTemplate(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): array {
    $tokensAlias = 'tokens_used';
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->exists('template');
    $query->aggregate('tokens_total', 'SUM', NULL, $tokensAlias);
    $query->groupBy('template');
    $rows = $query->execute();

    $tokensByTemplate = [];
    foreach ($rows as $row) {
      $tokensByTemplate[(string) $row['template']] = (int) ($row[$tokensAlias] ?? 0);
    }

    arsort($tokensByTemplate);
    return $tokensByTemplate;
  }

  /**
   * Returns the distinct session count per drafting template in a date range.
   *
   * The provenance records are grouped by template and session so each
   * (template, session) pair appears once; the pairs are then tallied per
   * template. Records without a template or session are skipped.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<string, int>
   *   The distinct session count keyed by template ID.
   */
  public function countSessionsByTemplate(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): array {
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->exists('template');
    $query->exists('session');
    $query->groupBy('template');
    $query->groupBy('session');
    $rows = $query->execute();

    $sessionsByTemplate = [];
    foreach ($rows as $row) {
      $templateId = (string) $row['template'];
      $sessionsByTemplate[$templateId] = ($sessionsByTemplate[$templateId] ?? 0) + 1;
    }

    return $sessionsByTemplate;
  }

  /**
   * Returns the average tokens used per session in an inclusive date range.
   *
   * @return float
   *   The mean tokens per session, or 0.0 when there are no sessions.
   */
  public function getAverageTokensPerSession(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): float {
    $sessions = $this->countSessions($startDate, $endDate, $filters);
    return $sessions > 0 ? $this->getTokensUsed($startDate, $endDate, $filters) / $sessions : 0.0;
  }

  /**
   * Returns the average tokens used per major version in a date range.
   *
   * @return float
   *   The mean tokens per major version, or 0.0 when there are none.
   */
  public function getAverageTokensPerMajorVersion(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): float {
    $versions = $this->countMajorVersions($startDate, $endDate, $filters);
    return $versions > 0 ? $this->getTokensUsed($startDate, $endDate, $filters) / $versions : 0.0;
  }

  /**
   * Counts the distinct non-empty values of a field in an inclusive range.
   *
   * @param string $field
   *   The field to count distinct values of.
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return int
   *   The number of distinct non-empty values.
   */
  private function countDistinct(string $field, string $startDate, string $endDate, ?DashboardFilters $filters = NULL): int {
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->exists($field);
    $query->groupBy($field);
    return count($query->execute());
  }

  /**
   * Builds a provenance aggregate query scoped to a date range and filters.
   *
   * Every dashboard query shares the same base: an aggregate query on the
   * provenance storage restricted to the created-date range. The optional
   * filters narrow it further by author user (a direct uid condition) and by
   * content type (resolved to node IDs, since the record stores the node ID
   * but not its bundle).
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return \Drupal\Core\Entity\Query\QueryAggregateInterface
   *   The configured aggregate query.
   */
  private function baseQuery(string $startDate, string $endDate, ?DashboardFilters $filters): QueryAggregateInterface {
    [$rangeStart, $rangeEnd] = $this->rangeTimestamps($startDate, $endDate);
    $query = $this->entityTypeManager->getStorage('ai_content_provenance')
      ->getAggregateQuery()
      ->accessCheck(FALSE);
    $query->condition('created', [$rangeStart, $rangeEnd], 'BETWEEN');

    if ($filters !== NULL && $filters->participant !== NULL) {
      $query->condition('uid', $filters->participant);
    }
    if ($filters !== NULL && $filters->contentType !== NULL) {
      // The record has no bundle column, so restrict to the node IDs of the
      // selected type. An empty set is forced to node ID 0, which never
      // matches, so the query returns no rows.
      $nodeIds = $this->entityTypeManager->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $filters->contentType)
        ->execute();
      $query->condition('entity_type', 'node');
      $query->condition('entity_id', $nodeIds ?: [0], 'IN');
    }

    return $query;
  }

  /**
   * Converts an inclusive Y-m-d range to UTC start/end Unix timestamps.
   *
   * The created field stores a Unix timestamp; the bounds cover the full
   * start and end days in UTC.
   *
   * @return array{0: int, 1: int}
   *   The start and end timestamps.
   */
  private function rangeTimestamps(string $startDate, string $endDate): array {
    $timezone = new \DateTimeZone('UTC');
    return [
      (new \DateTimeImmutable($startDate, $timezone))->setTime(0, 0)->getTimestamp(),
      (new \DateTimeImmutable($endDate, $timezone))->setTime(23, 59, 59)->getTimestamp(),
    ];
  }

}
