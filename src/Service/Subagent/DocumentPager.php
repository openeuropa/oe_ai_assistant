<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Subagent;

use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Exception\DocumentPagerException;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;

/**
 * Serves the extracted text of a session's documents one page at a time.
 *
 * Only documents of the given session that have an extract are served. A
 * page is a fixed run of characters, numbered from 1.
 */
final class DocumentPager {

  /**
   * Characters of extracted text in one page.
   */
  public const int PAGE_CHARS = 4000;

  /**
   * Characters of a snippet on either side of the first hit.
   */
  private const int SNIPPET_RADIUS = 200;

  /**
   * Shortest search term that counts.
   */
  private const int MIN_TERM_CHARS = 3;

  public function __construct(
    private readonly ContextDocumentRepository $documents,
  ) {}

  /**
   * Lists the extracted documents of the session.
   *
   * @return array<int, array{document_id: int, title: string, summary: string, characters: int, pages: int}>
   *   One entry per document.
   */
  public function listDocuments(ContentCreationSessionInterface $session): array {
    $list = [];
    foreach ($this->extracted($session) as $id => $document) {
      $list[] = [
        'document_id' => $id,
        'title' => $document['title'],
        'summary' => $document['summary'],
        'characters' => mb_strlen($document['extract']),
        'pages' => $this->pageCount($document['extract']),
      ];
    }

    return $list;
  }

  /**
   * Reads one page of a document.
   *
   * @return array{document_id: int, title: string, page: int, pages: int, text: string}
   *   The page.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\DocumentPagerException
   *   When the document is not an extracted document of the session, or the
   *   page does not exist.
   */
  public function read(ContentCreationSessionInterface $session, int $documentId, int $page): array {
    $documents = $this->extracted($session);
    if (!isset($documents[$documentId])) {
      throw new DocumentPagerException(sprintf(
        'Document %d is not an available document of this session. Call research_list_documents to see the valid ids.',
        $documentId,
      ));
    }
    $extract = $documents[$documentId]['extract'];
    $pages = $this->pageCount($extract);
    if ($page < 1 || $page > $pages) {
      throw new DocumentPagerException(sprintf(
        'Page %d does not exist: document %d has pages 1 to %d.',
        $page,
        $documentId,
        $pages,
      ));
    }

    return [
      'document_id' => $documentId,
      'title' => $documents[$documentId]['title'],
      'page' => $page,
      'pages' => $pages,
      'text' => $this->pageText($extract, $page),
    ];
  }

  /**
   * Finds the pages that contain the terms of a query.
   *
   * @return array<int, array{document_id: int, title: string, page: int, snippet: string}>
   *   The best pages first, at most $limit of them.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\DocumentPagerException
   *   When the query holds no term of at least three characters.
   */
  public function search(ContentCreationSessionInterface $session, string $query, int $limit = 10): array {
    $terms = array_values(array_unique(array_filter(
      preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [],
      static fn (string $term): bool => mb_strlen($term) >= self::MIN_TERM_CHARS,
    )));
    if ($terms === []) {
      throw new DocumentPagerException('The query has no search term. Give at least one word of 3 or more characters.');
    }

    $matches = [];
    foreach ($this->extracted($session) as $id => $document) {
      $pages = $this->pageCount($document['extract']);
      for ($page = 1; $page <= $pages; $page++) {
        $text = $this->pageText($document['extract'], $page);
        $lower = mb_strtolower($text);
        $hits = 0;
        $first = NULL;
        foreach ($terms as $term) {
          $count = mb_substr_count($lower, $term);
          if ($count === 0) {
            continue;
          }
          $hits += $count;
          $position = (int) mb_strpos($lower, $term);
          $first = $first === NULL ? $position : min($first, $position);
        }
        if ($first === NULL) {
          continue;
        }
        $matches[] = [
          'hits' => $hits,
          'result' => [
            'document_id' => $id,
            'title' => $document['title'],
            'page' => $page,
            'snippet' => $this->snippet($text, $first),
          ],
        ];
      }
    }

    // The sort is stable, so pages with the same hits keep document order.
    usort($matches, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);

    return array_column(array_slice($matches, 0, max(0, $limit)), 'result');
  }

  /**
   * Returns the session's documents that have an extract, by document id.
   *
   * @return array<int, array{title: string, summary: string, extract: string}>
   *   The documents.
   */
  private function extracted(ContentCreationSessionInterface $session): array {
    $documents = [];
    foreach ($this->documents->describe($session) as $document) {
      $extract = (string) ($document['extract'] ?? '');
      if ($extract === '') {
        continue;
      }
      $documents[(int) $document['id']] = [
        'title' => (string) $document['title'],
        'summary' => (string) ($document['summary'] ?? ''),
        'extract' => $extract,
      ];
    }

    return $documents;
  }

  /**
   * Counts the pages of an extract.
   */
  private function pageCount(string $extract): int {
    return (int) ceil(mb_strlen($extract) / self::PAGE_CHARS);
  }

  /**
   * Returns the text of one page of an extract.
   */
  private function pageText(string $extract, int $page): string {
    return mb_substr($extract, ($page - 1) * self::PAGE_CHARS, self::PAGE_CHARS);
  }

  /**
   * Cuts the text around a position, marking where it was cut.
   */
  private function snippet(string $text, int $position): string {
    $start = max(0, $position - self::SNIPPET_RADIUS);
    $end = min(mb_strlen($text), $position + self::SNIPPET_RADIUS);
    $snippet = mb_substr($text, $start, $end - $start);

    return ($start > 0 ? '…' : '') . $snippet . ($end < mb_strlen($text) ? '…' : '');
  }

}
