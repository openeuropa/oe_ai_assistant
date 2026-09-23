<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Tools;

use Drupal\oe_ai_assistant\Neuron\Tools\GetEditorialContextTool;
use Drupal\oe_ai_assistant\Neuron\Tools\ReadDocumentTool;
use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the tools that answer from the editorial context.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Tools\GetEditorialContextTool
 */
class EditorialContextToolsTest extends TestCase {

  /**
   * Builds a context with one processed and one pending document.
   */
  private function context(): EditorialContext {
    return new EditorialContext(
      toneId: '3',
      toneLabel: 'Formal',
      tonePrompt: 'Use professional, institutional language.',
      templateId: 'news_default',
      templateLabel: 'News default',
      contextDocuments: [
        [
          'id' => '12',
          'title' => 'Climate briefing',
          'filename' => 'climate.pdf',
          'status' => 'done',
          'summary' => 'Key figures on EU emissions.',
          'extract' => 'Emissions fell by 8 percent in 2025.',
        ],
        [
          'id' => '15',
          'title' => 'Draft speech',
          'filename' => 'speech.docx',
          'status' => 'scheduled',
          'summary' => '',
          'extract' => NULL,
        ],
      ],
    );
  }

  /**
   * @covers ::__invoke
   */
  public function testTheContextReportsSummariesAndNotTheText(): void {
    $reported = json_decode((new GetEditorialContextTool($this->context()))(), TRUE);

    $this->assertSame('Formal', $reported['tone']['label']);
    $this->assertSame('Use professional, institutional language.', $reported['tone']['guidelines']);
    $this->assertSame('News default', $reported['template']['label']);
    $this->assertSame([
      [
        'id' => '12',
        'title' => 'Climate briefing',
        'filename' => 'climate.pdf',
        'status' => 'done',
        'summary' => 'Key figures on EU emissions.',
      ],
      [
        'id' => '15',
        'title' => 'Draft speech',
        'filename' => 'speech.docx',
        'status' => 'scheduled',
        'summary' => '',
      ],
    ], $reported['documents'], 'The listing carries summaries, never the extracted text.');
  }

  /**
   * @covers ::__invoke
   */
  public function testEmptyContextReportsNothingSet(): void {
    $reported = json_decode((new GetEditorialContextTool(new EditorialContext(NULL, NULL, NULL, NULL, NULL)))(), TRUE);

    $this->assertNull($reported['tone']);
    $this->assertNull($reported['template']);
    $this->assertSame([], $reported['documents']);
  }

  /**
   * @covers \Drupal\oe_ai_assistant\Neuron\Tools\ReadDocumentTool::__invoke
   */
  public function testReadingOneDocumentReturnsItsTextOrItsState(): void {
    $tool = new ReadDocumentTool($this->context());

    $read = json_decode($tool('12'), TRUE);
    $this->assertSame('Climate briefing', $read['title']);
    $this->assertSame('Emissions fell by 8 percent in 2025.', $read['text']);

    // A document still in the pipeline reports its state, not a failure.
    $pending = json_decode($tool('15'), TRUE);
    $this->assertNull($pending['text']);
    $this->assertSame('scheduled', $pending['status']);

    $missing = json_decode($tool('99'), TRUE);
    $this->assertStringContainsString('No document 99', $missing['error']);
    $this->assertStringContainsString('12, 15', $missing['error']);
  }

  /**
   * @covers \Drupal\oe_ai_assistant\Neuron\Tools\ReadDocumentTool::properties
   */
  public function testTheDocumentArgumentOffersTheAttachedIds(): void {
    $properties = (new ReadDocumentTool($this->context()))->getProperties();

    $this->assertCount(1, $properties);
    $this->assertSame('document', $properties[0]->getName());
    $this->assertSame(['12', '15'], $properties[0]->getJsonSchema()['enum']);
  }

}
