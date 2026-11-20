<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Subagent;

/**
 * Turns the final text of the researcher into a report with citations.
 */
final class ResearchReport {

  /**
   * The most pages a range of a marker expands to.
   */
  private const int MAX_RANGE = 20;

  /**
   * Parses a report.
   *
   * @param string $text
   *   The final text of the researcher.
   * @param array $log
   *   The log of the researcher's tool calls.
   *
   * @return array
   *   The report, and the citations it carries. A citation is verified when
   *   the researcher read the page it cites or a search returned it.
   */
  public static function parse(string $text, array $log): array {
    // A marker names one page, a range of pages, or a list of both.
    preg_match_all('/\[doc:(\d+)\s+pp?\.\s*(\d+(?:\s*[\x{2013}-]\s*\d+)?(?:\s*,\s*(?:pp?\.\s*)?\d+(?:\s*[\x{2013}-]\s*\d+)?)*)\]/u', $text, $matches, PREG_SET_ORDER);

    $citations = [];
    foreach ($matches as $match) {
      $documentId = (int) $match[1];
      foreach (self::pages($match[2]) as $page) {
        $citations[$documentId . ':' . $page] ??= [
          'document_id' => $documentId,
          'page' => $page,
          'verified' => self::seen($log, $documentId, $page),
        ];
      }
    }

    return ['report' => $text, 'citations' => array_values($citations)];
  }

  /**
   * Expands the pages of a marker into a list of page numbers.
   *
   * @param string $spec
   *   The pages of a marker, such as "4", "4-5" or "4, 7-8".
   *
   * @return int[]
   *   The pages, a range counting at most MAX_RANGE of them.
   */
  private static function pages(string $spec): array {
    $pages = [];
    foreach (explode(',', $spec) as $part) {
      $bounds = preg_split('/\s*[\x{2013}-]\s*/u', preg_replace('/^\s*pp?\.\s*/', '', $part));
      $first = (int) $bounds[0];
      $last = isset($bounds[1]) ? max($first, min((int) $bounds[1], $first + self::MAX_RANGE - 1)) : $first;
      foreach (range($first, $last) as $page) {
        $pages[] = $page;
      }
    }

    return $pages;
  }

  /**
   * Whether a successful call of the log read or returned a page.
   */
  private static function seen(array $log, int $documentId, int $page): bool {
    foreach ($log as $entry) {
      if (!empty($entry['error'])) {
        continue;
      }

      $input = (array) ($entry['input'] ?? []);
      if (($entry['tool'] ?? '') === 'research_read_document'
        && (int) ($input['document_id'] ?? 0) === $documentId
        && (int) ($input['page'] ?? 0) === $page) {
        return TRUE;
      }

      if (($entry['tool'] ?? '') === 'research_search_documents' && self::listed((string) ($entry['result'] ?? ''), $documentId, $page)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether the JSON of a search result lists a page of a document.
   */
  private static function listed(string $json, int $documentId, int $page): bool {
    $decoded = json_decode($json, TRUE);
    if (!is_array($decoded)) {
      return FALSE;
    }

    // The matches are the list itself, or sit under a key of the result.
    $lists = array_is_list($decoded) ? [$decoded] : array_filter($decoded, 'is_array');
    foreach ($lists as $list) {
      foreach ($list as $hit) {
        if (is_array($hit) && (int) ($hit['document_id'] ?? 0) === $documentId && (int) ($hit['page'] ?? 0) === $page) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
