<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Subagent;

use Drupal\oe_ai_assistant\Neuron\Subagent\ResearchReport;
use Drupal\Tests\UnitTestCase;
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

}
