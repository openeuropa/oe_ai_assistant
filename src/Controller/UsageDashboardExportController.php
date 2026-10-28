<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\oe_ai_assistant\Service\DashboardFilters;
use Drupal\oe_ai_assistant\Service\UsageDashboardBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exports the current AI usage dashboard drill-down view as CSV.
 *
 * Reads the same query parameters as the dashboard form (view, preset,
 * content_type, participant) and asks the builder for the exact header and
 * rows the on-screen table would render, then streams them as a CSV download.
 */
class UsageDashboardExportController extends ControllerBase {

  /**
   * The views this controller can export.
   */
  private const VIEWS = ['nodes', 'major-versions', 'sessions', 'users'];

  /**
   * Constructs the controller.
   *
   * @param \Drupal\oe_ai_assistant\Service\UsageDashboardBuilder $dashboardBuilder
   *   The dashboard render array builder, reused for its table data.
   */
  public function __construct(
    private readonly UsageDashboardBuilder $dashboardBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get(UsageDashboardBuilder::class),
    );
  }

  /**
   * Streams the requested drill-down view as a CSV file.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request carrying the view and filter query parameters.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The CSV response.
   */
  public function export(Request $request): Response {
    $view = (string) $request->query->get('view', '');
    if (!in_array($view, self::VIEWS, TRUE)) {
      // Default to the nodes view when the parameter is missing or unknown.
      $view = 'nodes';
    }

    // Resolve the date range from the preset, mirroring the dashboard form.
    $preset = (string) $request->query->get('preset', '30-days');
    [$startDate, $endDate] = UsageDashboardBuilder::presetRange($preset);

    $contentType = (string) $request->query->get('content_type', '');
    $participant = (string) $request->query->get('participant', '');
    $filters = new DashboardFilters(
      $contentType !== '' ? $contentType : NULL,
      $participant !== '' ? $participant : NULL,
    );

    [$headers, $rows] = $this->dashboardBuilder->viewTable($view, $startDate, $endDate, $filters);

    $csv = $this->toCsv(array_merge([$headers], $rows));

    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="ai-usage-' . $view . '.csv"',
    );

    return $response;
  }

  /**
   * Serialises a list of rows to a CSV string.
   *
   * @param array<int, array<int, string>> $rows
   *   The rows to serialise, each a flat array of cell strings.
   *
   * @return string
   *   The CSV document.
   */
  private function toCsv(array $rows): string {
    // Write through a memory stream so fputcsv handles quoting and escaping.
    $handle = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
      // Pass an explicit (empty) escape string: the default backslash escape
      // is deprecated from PHP 8.4 and produces non-standard CSV.
      fputcsv($handle, $row, ',', '"', '');
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    return $csv;
  }

}
