<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\AiEditorialContext;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;

/**
 * Tests the tools that let an agent answer from the editorial context.
 *
 * @group oe_ai_assistant
 */
class EditorialContextToolsTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', ['chat' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
      ->save();
    MockAiProvider::reset();
  }

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
   * Tests that editorial agent instructions carry no document or tone text.
   *
   * Both used to be injected into this prompt directly; they now reach the
   * model only through context_get_editorial_context and
   * context_read_document. Nothing asserted their absence here, so a
   * regression that puts either back into instructions() would pass
   * unnoticed.
   */
  public function testEditorialAgentInstructionsCarryNoDocumentOrToneText(): void {
    $this->enableModules(['entity_reference_revisions', 'paragraphs', 'link', 'inline_entity_form']);
    $this->installEntitySchema('paragraph');
    $this->config('ai.settings')
      ->set('default_providers', ['chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
      ->save();
    $session = $this->stubbedSession([
      'id' => '3',
      'label' => 'Formal',
      'prompt' => 'Use professional, institutional language.',
    ], [
      self::document('1', 'done', 'The quarterly figures are confidential.', 'Summary one.'),
    ]);

    $agent = $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('editorial', ['session' => $session]);

    $instructions = $agent->getInstructions()->getContent();

    $this->assertStringNotContainsString('The quarterly figures are confidential.', $instructions);
    $this->assertStringNotContainsString('Use professional, institutional language.', $instructions);
  }

  /**
   * Tests that the context tool reports the tone, never its guidelines text.
   *
   * The guidelines text never reaches the sub-agent's system prompt or its
   * answer: context_read_document, not this tool, is what reads a
   * document's full text, and tonePrompt() on EditorialSessionAgentBase -
   * not this tool - is what carries tone guidelines to a drafter.
   */
  public function testContextToolReportsTheToneLabelNeverItsGuidelines(): void {
    $session = $this->stubbedSession([
      'id' => '3',
      'label' => 'Formal',
      'prompt' => 'Use professional, institutional language.',
    ], [
      self::document('1', 'done', 'Full text of one.', 'Summary one.'),
    ]);

    MockAiProvider::enqueue(new MockResponse('Formal tone; one document attached, summarised below.'));
    $answer = json_decode($this->contextTool($session, 'What tone and documents are set up?'), TRUE);

    $this->assertSame('Formal tone; one document attached, summarised below.', $answer['answer']);

    $prompt = MockAiProvider::getCallLog()[0]['system_prompt'];
    $this->assertStringContainsString('- Selected: Formal', $prompt);
    $this->assertStringContainsString('Summary one.', $prompt);
    $this->assertStringNotContainsString('Full text of one.', $prompt);
    $this->assertStringNotContainsString('Use professional, institutional language.', $prompt);
  }

  /**
   * Tests that a session with nothing set up selects no tone or template.
   */
  public function testContextToolReportsNothingSetUp(): void {
    $session = $this->stubbedSession(NULL, []);

    MockAiProvider::enqueue(new MockResponse('Nothing is set up yet.'));
    $answer = json_decode($this->contextTool($session, 'What is set up?'), TRUE);

    $this->assertSame('Nothing is set up yet.', $answer['answer']);

    $prompt = MockAiProvider::getCallLog()[0]['system_prompt'];
    $this->assertSame(2, substr_count($prompt, '- Selected: none selected'));
    $this->assertStringContainsString('Documents: none attached.', $prompt);
  }

  /**
   * Tests that the tool answers from the tones and templates on offer.
   *
   * The session only records what the editor picked, so without the
   * options the agent can't answer "which tones can I choose from?". The
   * options come from the same two services that feed the app's own
   * pickers, so the agent and the picker name the same tones.
   */
  public function testContextToolReportsTheOptionsTheEditorCanPick(): void {
    $formal = $this->createTone('Formal', 'Short, institutional sentences.', 'Use professional, institutional language.');
    $this->createTone('Plain', 'Everyday words and short sentences.', 'Write plainly.');
    AiDraftingTemplate::create([
      'id' => 'news_brief',
      'label' => 'News brief',
      'description' => 'A short news item.',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'Title.']],
    ])->save();

    $session = $this->createSession($this->createUser());
    $session->set('tone', $formal->id())->set('template', 'news_brief')->save();

    MockAiProvider::enqueue(new MockResponse('Formal is selected; Plain is also available, and so is News brief.'));
    $answer = json_decode($this->contextTool($session, 'Which tones and templates can I pick from?'), TRUE);

    $this->assertSame('Formal is selected; Plain is also available, and so is News brief.', $answer['answer']);

    $prompt = MockAiProvider::getCallLog()[0]['system_prompt'];
    $this->assertStringContainsString('- Selected: Formal - Short, institutional sentences.', $prompt);
    $this->assertStringContainsString('  - Formal: Short, institutional sentences.', $prompt);
    $this->assertStringContainsString('  - Plain: Everyday words and short sentences.', $prompt);
    $this->assertStringContainsString('- Selected: News brief - A short news item.', $prompt);
    $this->assertStringContainsString('  - News brief: A short news item.', $prompt);

    // An option names and describes a tone. The guidelines text stays out
    // of the prompt, for the selected tone as much as for the others.
    $this->assertStringNotContainsString('Use professional, institutional language.', $prompt);
    $this->assertStringNotContainsString('Write plainly.', $prompt);
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
   * Tests that the document property's enum lists the attached ids.
   *
   * NeuronToolManager::createTool() applies the session context before
   * calling getNeuron(), which is what calls properties(), so the session
   * is always set by the time the enum is built.
   */
  public function testReadDocumentPropertyEnumeratesAttachedIds(): void {
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
    $this->assertSame(['1', '2'], $properties[0]->getEnum());
  }

  /**
   * Builds the context tool bound to the session, and asks it one question.
   */
  private function contextTool(AiEditorialSessionInterface $session, string $question): string {
    $tool = $this->container->get(NeuronToolManagerInterface::class)->createTool(
      'context_get_editorial_context',
      ['session' => $session],
    );

    return (string) $tool($question);
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
   * Creates an enabled tone term with its description and guidelines.
   */
  private function createTone(string $label, string $description, string $prompt): TermInterface {
    $term = Term::create([
      'vid' => 'oe_ai_tone',
      'name' => $label,
      'description' => $description,
      'field_oe_ai_prompt' => $prompt,
    ]);
    $term->save();

    return $term;
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
