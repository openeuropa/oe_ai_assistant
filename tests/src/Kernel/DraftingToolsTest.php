<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBrief;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface;
use NeuronAI\Tools\ToolInterface;

/**
 * Tests the tools that answer from what the editor set up.
 *
 * A tool takes the session as plugin context and reads the rest off it, so the
 * tools are built through their manager on a real session. What the session
 * holds is the brief's business, which a stub stands in for here.
 *
 * @group oe_ai_assistant
 */
class DraftingToolsTest extends AiEditorialSessionKernelTestBase {

  /**
   * The tone the stubbed brief answers with.
   */
  private const TONE = [
    'id' => '3',
    'label' => 'Formal',
    'prompt' => 'Use professional, institutional language.',
  ];

  /**
   * The template the stubbed brief answers with.
   */
  private const TEMPLATE = ['id' => 'news_default', 'label' => 'News default'];

  /**
   * One processed document and one still in the pipeline.
   */
  private const DOCUMENTS = [
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
  ];

  /**
   * Builds a tool on a session the stubbed brief answers for.
   *
   * @param string $id
   *   The tool's plugin id.
   * @param array|null $tone
   *   The tone the brief answers with.
   * @param array|null $template
   *   The template the brief answers with.
   * @param array $documents
   *   The documents the brief answers with.
   *
   * @return \NeuronAI\Tools\ToolInterface
   *   The tool the agent would be given, built on its session.
   */
  private function tool(string $id, ?array $tone = NULL, ?array $template = NULL, array $documents = []): ToolInterface {
    // The interface is an alias of the class, and an alias resolves first, so
    // the stub goes in under the class.
    $this->container->set(DraftingBrief::class, new class($tone, $template, $documents) implements DraftingBriefInterface {

      /**
       * @param array|null $tone
       *   The tone every session is set up with.
       * @param array|null $template
       *   The template every session is set up with.
       * @param array $documents
       *   The documents every session is set up with.
       */
      public function __construct(
        private readonly ?array $tone,
        private readonly ?array $template,
        private readonly array $documents,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function tone(AiEditorialSessionInterface $session): ?array {
        return $this->tone;
      }

      /**
       * {@inheritdoc}
       */
      public function template(AiEditorialSessionInterface $session): ?array {
        return $this->template;
      }

      /**
       * {@inheritdoc}
       */
      public function documents(AiEditorialSessionInterface $session): array {
        return $this->documents;
      }

      /**
       * {@inheritdoc}
       */
      public function groups(AiEditorialSessionInterface $session): array {
        return [];
      }

    });

    $session = $this->createSession($this->createUser());

    return $this->container->get(NeuronToolManagerInterface::class)->createTool($id, ['session' => $session]);
  }

  /**
   * Builds the context tool on everything the editor can set up.
   */
  private function contextTool(): ToolInterface {
    return $this->tool('get_editorial_context', self::TONE, self::TEMPLATE, self::DOCUMENTS);
  }

  /**
   * Builds the document tool on the attached documents.
   */
  private function documentTool(): ToolInterface {
    return $this->tool('read_document', self::TONE, self::TEMPLATE, self::DOCUMENTS);
  }

  /**
   * @covers \Drupal\oe_ai_assistant\Plugin\NeuronTool\GetEditorialContextNeuronTool::__invoke
   */
  public function testTheContextReportsSummariesAndNotTheText(): void {
    $reported = json_decode(($this->contextTool())(), TRUE);

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
   * @covers \Drupal\oe_ai_assistant\Plugin\NeuronTool\GetEditorialContextNeuronTool::__invoke
   */
  public function testNothingSetUpReportsNothing(): void {
    $reported = json_decode(($this->tool('get_editorial_context'))(), TRUE);

    $this->assertNull($reported['tone']);
    $this->assertNull($reported['template']);
    $this->assertSame([], $reported['documents']);
  }

  /**
   * @covers \Drupal\oe_ai_assistant\Plugin\NeuronTool\ReadDocumentNeuronTool::__invoke
   */
  public function testReadingOneDocumentReturnsItsTextOrItsState(): void {
    $tool = $this->documentTool();

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
    $properties = $this->documentTool()->getProperties();

    $this->assertCount(1, $properties);
    $this->assertSame('document', $properties[0]->getName());
    $this->assertSame(['12', '15'], $properties[0]->getJsonSchema()['enum']);
  }

}
