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
   * Returns per-node token rows for an inclusive date range.
   *
   * Each AI-supported node contributes one row with its distinct major-version
   * count, distinct session count, and summed tokens. Rows are sorted by tokens
   * used, descending. Nodes that no longer load are skipped.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<int, array{node_id: int, label: string, bundle: string, uid: int|null, template: bool, majors: int, sessions: int, tokens: int}>
   *   The node rows, highest token usage first.
   */
  public function getNodeRows(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): array {
    $tokensAlias = 'tokens_used';
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->condition('entity_type', 'node');
    $query->exists('entity_id');
    $query->aggregate('tokens_total', 'SUM', NULL, $tokensAlias);
    $query->groupBy('entity_id');
    $rows = $query->execute();

    // Collect the per-node token totals keyed by node ID, highest first.
    $tokensByNode = [];
    foreach ($rows as $row) {
      $tokensByNode[(int) $row['entity_id']] = (int) ($row[$tokensAlias] ?? 0);
    }
    if ($tokensByNode === []) {
      return [];
    }
    arsort($tokensByNode);

    // The distinct major-version and session counts per node need their own
    // grouped queries; the aggregate query cannot count distinct values in the
    // same pass as the token sum.
    $majorsByNode = $this->distinctCountByNode('version_major', $startDate, $endDate, $filters);
    $sessionsByNode = $this->distinctCountByNode('session', $startDate, $endDate, $filters);
    // A node "uses a template" when any of its records reference one; capture
    // the authoring user and the template flag in one grouped pass.
    [$uidByNode, $templateByNode] = $this->nodeAuthorAndTemplate($startDate, $endDate, $filters);

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadMultiple(array_keys($tokensByNode));
    $result = [];
    foreach ($tokensByNode as $nodeId => $tokens) {
      $node = $nodes[$nodeId] ?? NULL;
      if ($node === NULL) {
        continue;
      }
      $result[] = [
        'node_id' => (int) $nodeId,
        'label' => (string) $node->label(),
        'bundle' => (string) $node->bundle(),
        'uid' => $uidByNode[$nodeId] ?? NULL,
        'template' => !empty($templateByNode[$nodeId]),
        'majors' => $majorsByNode[$nodeId] ?? 0,
        'sessions' => $sessionsByNode[$nodeId] ?? 0,
        'tokens' => $tokens,
      ];
    }

    return $result;
  }

  /**
   * Returns per-major-version token rows for an inclusive date range.
   *
   * Each (node, major version) pair contributes one row with its distinct
   * session count and summed tokens. Rows are sorted by tokens used,
   * descending. Pairs whose node no longer loads are skipped.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<int, array{node_id: int, label: string, bundle: string, uid: int|null, template: bool, major: int, sessions: int, tokens: int}>
   *   The major-version rows, highest token usage first.
   */
  public function getMajorVersionRows(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): array {
    $tokensAlias = 'tokens_used';
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->condition('entity_type', 'node');
    $query->exists('entity_id');
    $query->exists('version_major');
    $query->aggregate('tokens_total', 'SUM', NULL, $tokensAlias);
    $query->groupBy('entity_id');
    $query->groupBy('version_major');
    $rows = $query->execute();
    if ($rows === []) {
      return [];
    }

    // Distinct session counts and the author/template flag per (node, major).
    $sessions = $this->sessionsByNodeMajor($startDate, $endDate, $filters);
    [$uidByNode, $templateByNode] = $this->nodeAuthorAndTemplate($startDate, $endDate, $filters);

    $nodeIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['entity_id'], $rows)));
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nodeIds);

    $result = [];
    foreach ($rows as $row) {
      $nodeId = (int) $row['entity_id'];
      $major = (int) $row['version_major'];
      $node = $nodes[$nodeId] ?? NULL;
      if ($node === NULL) {
        continue;
      }
      $result[] = [
        'node_id' => $nodeId,
        'label' => (string) $node->label(),
        'bundle' => (string) $node->bundle(),
        'uid' => $uidByNode[$nodeId] ?? NULL,
        'template' => !empty($templateByNode[$nodeId]),
        'major' => $major,
        'sessions' => $sessions[$nodeId][$major] ?? 0,
        'tokens' => (int) ($row[$tokensAlias] ?? 0),
      ];
    }

    // Sort by tokens descending; the grouped query cannot order by the sum.
    usort($result, static fn (array $a, array $b): int => $b['tokens'] <=> $a['tokens']);
    return $result;
  }

  /**
   * Returns per-session token rows for an inclusive date range.
   *
   * Each session contributes one row with its node, major version, author,
   * template flag, and summed tokens. Rows are sorted by tokens used,
   * descending. Sessions whose node no longer loads are skipped.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<int, array{session_id: int, major: int|null, node_id: int, label: string, bundle: string, uid: int|null, template: bool, tokens: int}>
   *   The session rows, highest token usage first.
   */
  public function getSessionRows(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): array {
    $tokensAlias = 'tokens_used';
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->condition('entity_type', 'node');
    $query->exists('entity_id');
    $query->exists('session');
    // The major version and template are grouped alongside the session so each
    // session resolves to a single node, major version, and template. The
    // aggregate alias is passed by reference, so each one needs a variable.
    $uidAlias = 'uid_value';
    $majorAlias = 'major_value';
    $templateAlias = 'template_value';
    $nodeAlias = 'node_value';
    $query->aggregate('tokens_total', 'SUM', NULL, $tokensAlias);
    $query->aggregate('uid', 'MAX', NULL, $uidAlias);
    $query->aggregate('version_major', 'MAX', NULL, $majorAlias);
    $query->aggregate('template', 'MAX', NULL, $templateAlias);
    $query->aggregate('entity_id', 'MAX', NULL, $nodeAlias);
    $query->groupBy('session');
    $rows = $query->execute();
    if ($rows === []) {
      return [];
    }

    $nodeIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['node_value'], $rows)));
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nodeIds);

    $result = [];
    foreach ($rows as $row) {
      $nodeId = (int) $row['node_value'];
      $node = $nodes[$nodeId] ?? NULL;
      if ($node === NULL) {
        continue;
      }
      $major = $row['major_value'];
      $uid = $row['uid_value'];
      $result[] = [
        'session_id' => (int) $row['session'],
        'major' => $major !== NULL ? (int) $major : NULL,
        'node_id' => $nodeId,
        'label' => (string) $node->label(),
        'bundle' => (string) $node->bundle(),
        'uid' => $uid !== NULL ? (int) $uid : NULL,
        'template' => $row['template_value'] !== NULL && $row['template_value'] !== '',
        'tokens' => (int) ($row[$tokensAlias] ?? 0),
      ];
    }

    usort($result, static fn (array $a, array $b): int => $b['tokens'] <=> $a['tokens']);
    return $result;
  }

  /**
   * Returns per-user token rows for an inclusive date range.
   *
   * Each author contributes one row with the distinct AI-edited node count,
   * distinct session count, and summed tokens. Rows are sorted by tokens used,
   * descending.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<int, array{uid: int, nodes: int, sessions: int, tokens: int}>
   *   The user rows, highest token usage first.
   */
  public function getUserRows(string $startDate, string $endDate, ?DashboardFilters $filters = NULL): array {
    $tokensByUser = $this->getTokensUsedByUser($startDate, $endDate, $filters);
    if ($tokensByUser === []) {
      return [];
    }

    $nodesByUser = $this->distinctCountByUser('entity_id', $startDate, $endDate, $filters);
    $sessionsByUser = $this->distinctCountByUser('session', $startDate, $endDate, $filters);

    $result = [];
    foreach ($tokensByUser as $uid => $tokens) {
      $result[] = [
        'uid' => (int) $uid,
        'nodes' => $nodesByUser[$uid] ?? 0,
        'sessions' => $sessionsByUser[$uid] ?? 0,
        'tokens' => (int) $tokens,
      ];
    }

    return $result;
  }

  /**
   * Returns the distinct count of a field grouped per node.
   *
   * Each (node, value) pair is produced once by the grouped query and then
   * tallied per node. Records without a node or value are skipped.
   *
   * @param string $field
   *   The field whose distinct values are counted (e.g. session).
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<int, int>
   *   The distinct count keyed by node ID.
   */
  private function distinctCountByNode(string $field, string $startDate, string $endDate, ?DashboardFilters $filters): array {
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->condition('entity_type', 'node');
    $query->exists('entity_id');
    $query->exists($field);
    $query->groupBy('entity_id');
    $query->groupBy($field);
    $rows = $query->execute();

    $counts = [];
    foreach ($rows as $row) {
      $nodeId = (int) $row['entity_id'];
      $counts[$nodeId] = ($counts[$nodeId] ?? 0) + 1;
    }

    return $counts;
  }

  /**
   * Returns the distinct count of a field grouped per author user.
   *
   * @param string $field
   *   The field whose distinct values are counted (e.g. entity_id, session).
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<int, int>
   *   The distinct count keyed by user ID.
   */
  private function distinctCountByUser(string $field, string $startDate, string $endDate, ?DashboardFilters $filters): array {
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->exists('uid');
    $query->exists($field);
    $query->groupBy('uid');
    $query->groupBy($field);
    $rows = $query->execute();

    $counts = [];
    foreach ($rows as $row) {
      $uid = (int) $row['uid'];
      $counts[$uid] = ($counts[$uid] ?? 0) + 1;
    }

    return $counts;
  }

  /**
   * Returns the distinct session count per (node, major version).
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array<int, array<int, int>>
   *   The distinct session count keyed by node ID then major version.
   */
  private function sessionsByNodeMajor(string $startDate, string $endDate, ?DashboardFilters $filters): array {
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->condition('entity_type', 'node');
    $query->exists('entity_id');
    $query->exists('version_major');
    $query->exists('session');
    $query->groupBy('entity_id');
    $query->groupBy('version_major');
    $query->groupBy('session');
    $rows = $query->execute();

    $counts = [];
    foreach ($rows as $row) {
      $nodeId = (int) $row['entity_id'];
      $major = (int) $row['version_major'];
      $counts[$nodeId][$major] = ($counts[$nodeId][$major] ?? 0) + 1;
    }

    return $counts;
  }

  /**
   * Returns the author user ID and template-usage flag per node.
   *
   * The author is the most recent uid seen for the node; the template flag is
   * TRUE when any of the node's records reference a drafting template.
   *
   * @param string $startDate
   *   The inclusive range start date in Y-m-d format.
   * @param string $endDate
   *   The inclusive range end date in Y-m-d format.
   * @param \Drupal\oe_ai_assistant\Service\DashboardFilters|null $filters
   *   The optional content type and participant filters.
   *
   * @return array{0: array<int, int>, 1: array<int, bool>}
   *   The author user ID keyed by node ID, and the template flag keyed by
   *   node ID.
   */
  private function nodeAuthorAndTemplate(string $startDate, string $endDate, ?DashboardFilters $filters): array {
    $query = $this->baseQuery($startDate, $endDate, $filters);
    $query->condition('entity_type', 'node');
    $query->exists('entity_id');
    // The aggregate alias is passed by reference, so each one needs a variable.
    $uidAlias = 'uid_value';
    $templateAlias = 'template_value';
    $query->aggregate('uid', 'MAX', NULL, $uidAlias);
    $query->aggregate('template', 'MAX', NULL, $templateAlias);
    $query->groupBy('entity_id');
    $rows = $query->execute();

    $uidByNode = [];
    $templateByNode = [];
    foreach ($rows as $row) {
      $nodeId = (int) $row['entity_id'];
      if ($row['uid_value'] !== NULL) {
        $uidByNode[$nodeId] = (int) $row['uid_value'];
      }
      $templateByNode[$nodeId] = $row['template_value'] !== NULL && $row['template_value'] !== '';
    }

    return [$uidByNode, $templateByNode];
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
