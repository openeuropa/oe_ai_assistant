<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Subagent;

use Drupal\oe_ai_assistant\Neuron\Subagent\ResearchReport;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the citations parsed from a research report.
 */
#[Group('oe_ai_assistant')]
class ResearchReportTest extends UnitTestCase {

  /**
   * Tests that citations are unique, ordered and verified against the log.
   */
  public function testCitations(): void {
    $search = json_encode(['results' => [['document_id' => 5, 'page' => 7, 'snippet' => 's']]]);
    $log = [
      [
        'tool' => 'research_read_document',
        'input' => ['document_id' => 4, 'page' => 2],
        'result' => '{}',
        'error' => FALSE,
      ],
      [
        'tool' => 'research_read_document',
        'input' => ['document_id' => 4, 'page' => 3],
        'result' => 'x',
        'error' => TRUE,
      ],
      [
        'tool' => 'research_search_documents',
        'input' => ['query' => 'a'],
        'result' => $search,
        'error' => FALSE,
      ],
    ];
    $text = 'A [doc:4 p.2]. B [doc:5 p.7]. C [doc:4 p.3]. A again [doc:4 p.2]. D [doc:9 p.1].';

    $this->assertSame([
      'report' => $text,
      'citations' => [
        ['document_id' => 4, 'page' => 2, 'verified' => TRUE],
        ['document_id' => 5, 'page' => 7, 'verified' => TRUE],
        ['document_id' => 4, 'page' => 3, 'verified' => FALSE],
        ['document_id' => 9, 'page' => 1, 'verified' => FALSE],
      ],
    ], ResearchReport::parse($text, $log));
  }

  /**
   * Tests that a report with no marker has no citations.
   */
  public function testNoCitations(): void {
    $this->assertSame(['report' => 'Nothing.', 'citations' => []], ResearchReport::parse('Nothing.', []));
  }

  /**
   * Tests that ranges and lists of pages expand into single-page citations.
   */
  #[DataProvider('pageMarkers')]
  public function testPageMarkers(string $marker, array $pages): void {
    $citations = ResearchReport::parse('Claim ' . $marker . '.', [])['citations'];

    $this->assertSame($pages, array_column($citations, 'page'));
    $this->assertSame([3], array_unique(array_column($citations, 'document_id')));
  }

  /**
   * Provides the forms of a marker and the pages each one cites.
   */
  public static function pageMarkers(): array {
    return [
      'single' => ['[doc:3 p.4]', [4]],
      'en dash range' => ['[doc:3 p.4–5]', [4, 5]],
      'hyphen range' => ['[doc:3 p.4-6]', [4, 5, 6]],
      'pp range' => ['[doc:3 pp.4–5]', [4, 5]],
      'list with p' => ['[doc:3 p.4, p.9]', [4, 9]],
      'list with bare page' => ['[doc:3 p.4, 9]', [4, 9]],
      'list with range' => ['[doc:3 p.4, 7-8]', [4, 7, 8]],
      'range capped at 20 pages' => ['[doc:3 p.1-90]', range(1, 20)],
    ];
  }

}
