<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Tools;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Plugin\NeuronTool\GetEditorialContextNeuronTool;
use Drupal\oe_ai_assistant\Plugin\NeuronTool\ReadDocumentNeuronTool;
use Drupal\oe_ai_assistant\Service\Drafting\DraftCollector;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn;
use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the tools that answer from the editorial context.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Plugin\NeuronTool\GetEditorialContextNeuronTool
 */
class EditorialContextToolsTest extends TestCase {

  /**
   * Opens a turn on a context, as the chat action does.
   */
  private function turn(EditorialContext $context): DraftingTurn {
    $turn = new DraftingTurn(
      $this->createMock(NeuronWorkflowManagerInterface::class),
      $this->createMock(DraftHistoryInterface::class),
    );
    $turn->open(
      $this->createMock(AiEditorialSessionInterface::class),
      $context,
      new DraftCollector([], static fn (array $fields): array => $fields),
      '',
      '',
      'node',
      'oe_news',
    );

    return $turn;
  }

  /**
   * Builds the context tool on an open turn.
   */
  private function contextTool(EditorialContext $context): GetEditorialContextNeuronTool {
    return new GetEditorialContextNeuronTool([], 'get_editorial_context', [], $this->turn($context));
  }

  /**
   * Builds the document tool on an open turn.
   */
  private function documentTool(EditorialContext $context): ReadDocumentNeuronTool {
    return new ReadDocumentNeuronTool([], 'read_document', [], $this->turn($context));
  }

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
    $reported = json_decode(($this->contextTool($this->context()))(), TRUE);

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
    $reported = json_decode(($this->contextTool(new EditorialContext(NULL, NULL, NULL, NULL, NULL)))(), TRUE);

    $this->assertNull($reported['tone']);
    $this->assertNull($reported['template']);
    $this->assertSame([], $reported['documents']);
  }

  /**
   * @covers \Drupal\oe_ai_assistant\Plugin\NeuronTool\ReadDocumentNeuronTool::__invoke
   */
  public function testReadingOneDocumentReturnsItsTextOrItsState(): void {
    $tool = $this->documentTool($this->context());

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
   * @covers \Drupal\oe_ai_assistant\Plugin\NeuronTool\ReadDocumentNeuronTool::properties
   */
  public function testTheDocumentArgumentOffersTheAttachedIds(): void {
    $properties = $this->documentTool($this->context())->getNeuron()->getProperties();

    $this->assertCount(1, $properties);
    $this->assertSame('document', $properties[0]->getName());
    $this->assertSame(['12', '15'], $properties[0]->getJsonSchema()['enum']);
  }

}
