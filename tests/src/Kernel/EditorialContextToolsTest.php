<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\AiEditorialContext;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface;

/**
 * Tests the tools that let an agent answer from the editorial context.
 *
 * @group oe_ai_assistant
 */
class EditorialContextToolsTest extends AiEditorialSessionKernelTestBase {

  /**
   * The editorial agent carries the two context tools and tells the model so.
   */
  public function testEditorialAgentCarriesTheContextTools(): void {
    $this->enableModules(['entity_reference_revisions', 'paragraphs', 'link', 'inline_entity_form']);
    $this->installEntitySchema('paragraph');
    $this->config('ai.settings')
      ->set('default_providers', ['chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
      ->save();
    $session = $this->createSession($this->createUser());

    $agent = $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('editorial', ['session' => $session]);

    $names = array_map(static fn ($tool) => $tool->getName(), $agent->getTools());
    $this->assertContains('context_get_editorial_context', $names);
    $this->assertContains('context_read_document', $names);

    $instructions = $agent->getInstructions()->getContent();
    $this->assertStringContainsString('context_get_editorial_context', $instructions);
    $this->assertStringContainsString('context_read_document', $instructions);
  }

  /**
   * Tests that the context tool reports the tone, template and documents.
   *
   * The summary is reported, never the extracted text: that is what
   * context_read_document is for.
   */
  public function testContextToolReportsSummariesNeverExtracts(): void {
    $session = $this->stubbedSession([
      'id' => '3',
      'label' => 'Formal',
      'prompt' => 'Use professional, institutional language.',
    ], [
      self::document('1', 'done', 'Full text of one.', 'Summary one.'),
    ]);

    $result = json_decode($this->contextTool($session), TRUE);

    $this->assertSame([
      'label' => 'Formal',
      'guidelines' => 'Use professional, institutional language.',
    ], $result['tone']);
    $this->assertNull($result['template']);
    $this->assertCount(1, $result['documents']);
    $this->assertSame('Summary one.', $result['documents'][0]['summary']);
    $this->assertArrayNotHasKey('extract', $result['documents'][0]);
    $this->assertStringNotContainsString('Full text of one.', $this->contextTool($session));
  }

  /**
   * Tests that nothing set up answers null tone and template.
   */
  public function testContextToolReportsNothingSetUp(): void {
    $session = $this->stubbedSession(NULL, []);

    $result = json_decode($this->contextTool($session), TRUE);

    $this->assertNull($result['tone']);
    $this->assertNull($result['template']);
    $this->assertSame([], $result['documents']);
  }

  /**
   * Tests that the document tool answers the text of a known document.
   */
  public function testReadDocumentAnswersTheExtract(): void {
    $session = $this->stubbedSession(NULL, [
      self::document('1', 'done', 'Full text of one.', 'Summary one.'),
    ]);

    $result = json_decode($this->documentTool($session, '1'), TRUE);

    $this->assertSame('1', $result['id']);
    $this->assertSame('Full text of one.', $result['text']);
    $this->assertSame('Summary one.', $result['summary']);
    $this->assertSame('done', $result['status']);
  }

  /**
   * Tests that the document tool answers NULL text for one not processed.
   */
  public function testReadDocumentAnswersNullTextWhenNotProcessed(): void {
    $session = $this->stubbedSession(NULL, [
      self::document('1', 'scheduled', NULL),
    ]);

    $result = json_decode($this->documentTool($session, '1'), TRUE);

    $this->assertNull($result['text']);
    $this->assertSame('scheduled', $result['status']);
  }

  /**
   * Tests that the document tool refuses an ID it was not given.
   */
  public function testReadDocumentRefusesAnUnknownId(): void {
    $session = $this->stubbedSession(NULL, [
      self::document('1', 'done', 'Full text.'),
    ]);

    $result = json_decode($this->documentTool($session, '99'), TRUE);

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('99', $result['error']);
  }

  /**
   * Tests that the document tool declares a plain string property.
   *
   * No enum: building one would read the session's documents while the
   * tool itself is still being constructed, which crashes any caller that
   * builds the editorial tool set generically, such as
   * NeuronToolManager::createTools(). An unknown id is refused by
   * __invoke() instead, once the tool actually runs.
   */
  public function testReadDocumentPropertyHasNoEnum(): void {
    $session = $this->stubbedSession(NULL, [
      self::document('1', 'done', 'Full text one.'),
      self::document('2', 'done', 'Full text two.'),
    ]);

    $tool = $this->container->get(NeuronToolManagerInterface::class)->createTool(
      'context_read_document',
      ['session' => $session],
    );

    $properties = $tool->getProperties();
    $this->assertCount(1, $properties);
    $this->assertSame('document', $properties[0]->getName());
    $this->assertSame([], $properties[0]->getEnum());
  }

  /**
   * Builds the context tool bound to the session, and calls it.
   */
  private function contextTool(AiEditorialSessionInterface $session): string {
    $tool = $this->container->get(NeuronToolManagerInterface::class)->createTool(
      'context_get_editorial_context',
      ['session' => $session],
    );

    return (string) $tool();
  }

  /**
   * Builds the document tool bound to the session, and calls it.
   */
  private function documentTool(AiEditorialSessionInterface $session, string $document): string {
    $tool = $this->container->get(NeuronToolManagerInterface::class)->createTool(
      'context_read_document',
      ['session' => $session],
    );

    return (string) $tool(document: $document);
  }

  /**
   * Builds a session with a stubbed tone and document set.
   */
  private function stubbedSession(?array $tone, array $documents): AiEditorialSessionInterface {
    $tones = $this->createMock(AiEditorialContextInterface::class);
    $tones->method('getTone')->willReturn($tone ?? []);
    $this->container->set(AiEditorialContext::class, $tones);
    $repository = $this->createMock(DocumentRepositoryInterface::class);
    $repository->method('describe')->willReturn($documents);
    $this->container->set(ContextDocumentRepository::class, $repository);

    $session = $this->createSession($this->createUser());
    if ($tone !== NULL) {
      $session->set('tone', 1)->save();
    }

    return $session;
  }

  /**
   * Builds a context document descriptor.
   */
  private static function document(string $id, string $status, ?string $extract, string $summary = ''): array {
    return [
      'id' => $id,
      'title' => 'Document ' . $id,
      'category' => 'context',
      'status' => $status,
      'filename' => 'doc-' . $id . '.pdf',
      'summary' => $summary,
      'meta' => ['type' => 'pdf', 'size' => 10],
      'extract' => $extract,
    ];
  }

}
