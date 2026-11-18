<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Tests the sub-agent that answers one question from the session's state.
 *
 * Each test inspects the system prompt MockAiProvider logged, the same way
 * SummaryNeuronAgentTest does: that prompt is instructions(), and it is the
 * only thing the agent has to answer from, since it holds no conversation.
 *
 * @group oe_ai_assistant
 */
class EditorialContextAnswerNeuronAgentTest extends AiEditorialSessionKernelTestBase {

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
   * Builds the agent with the session context the plugin requires.
   */
  private function agent(AiEditorialSessionInterface $session): Agent {
    return $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('editorial_context_answer', ['session' => $session]);
  }

  /**
   * Sends one question and returns the system prompt the provider logged.
   */
  private function systemPromptFor(AiEditorialSessionInterface $session): string {
    MockAiProvider::enqueue(new MockResponse('An answer.'));
    $this->agent($session)->chat(new UserMessage('What is set up for this session?'));

    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log);

    return $log[0]['system_prompt'];
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
   * Tests that the selected tone and template, and all options, are rendered.
   *
   * The guidelines text a tone carries must never reach this prompt: it is
   * read fresh by GroupDrafterNeuronAgent through tonePrompt() instead, so a
   * copy baked in here would go stale the moment the editor picked another
   * tone.
   */
  public function testRendersSelectedAndAvailableToneAndTemplateOptions(): void {
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

    $prompt = $this->systemPromptFor($session);

    $this->assertStringContainsString('- Selected: Formal - Short, institutional sentences.', $prompt);
    $this->assertStringContainsString('  - Formal: Short, institutional sentences.', $prompt);
    $this->assertStringContainsString('  - Plain: Everyday words and short sentences.', $prompt);
    $this->assertStringContainsString('- Selected: News brief - A short news item.', $prompt);
    $this->assertStringContainsString('  - News brief: A short news item.', $prompt);

    $this->assertStringNotContainsString('Use professional, institutional language.', $prompt);
    $this->assertStringNotContainsString('Write plainly.', $prompt);
  }

  /**
   * Tests that a session with nothing set up renders "none selected".
   */
  public function testRendersNoneSelectedAndNoDocumentsWhenNothingIsSetUp(): void {
    $session = $this->createSession($this->createUser());

    $prompt = $this->systemPromptFor($session);

    $this->assertSame(2, substr_count($prompt, '- Selected: none selected'));
    $this->assertStringContainsString('Documents: none attached.', $prompt);
  }

  /**
   * Tests that each document is rendered with its ID and processing state.
   *
   * The ID has to appear even though it is not what a human would read
   * first, because it is the only place this agent's answer can carry the
   * ID context_read_document needs to look the document up again.
   */
  public function testRendersDocumentsWithIdAndProcessingState(): void {
    $documents = [
      [
        'id' => '1',
        'title' => 'Q3 Budget',
        'category' => 'context',
        'status' => DocumentExtractionProcessorInterface::STATE_DONE,
        'filename' => 'q3-budget.pdf',
        'summary' => 'The Q3 marketing budget.',
        'meta' => ['type' => 'pdf', 'size' => 10],
        'extract' => 'The full budget text.',
      ],
      [
        'id' => '2',
        'title' => 'Press Release',
        'category' => 'context',
        'status' => DocumentExtractionProcessorInterface::STATE_SCHEDULED,
        'filename' => 'press-release.pdf',
        'summary' => '',
        'meta' => ['type' => 'pdf', 'size' => 10],
        'extract' => NULL,
      ],
      [
        'id' => '3',
        'title' => 'Notes',
        'category' => 'context',
        'status' => DocumentExtractionProcessorInterface::STATE_ERROR,
        'filename' => '',
        'summary' => '',
        'meta' => ['type' => 'pdf', 'size' => 10],
        'extract' => NULL,
      ],
    ];
    $repository = $this->createMock(DocumentRepositoryInterface::class);
    $repository->method('describe')->willReturn($documents);
    $this->container->set(ContextDocumentRepository::class, $repository);

    $session = $this->createSession($this->createUser());

    $prompt = $this->systemPromptFor($session);

    $this->assertStringContainsString('- Q3 Budget (ID: 1, file: q3-budget.pdf)', $prompt);
    $this->assertStringContainsString('  Summary: The Q3 marketing budget.', $prompt);

    $this->assertStringContainsString('- Press Release (ID: 2, file: press-release.pdf)', $prompt);
    $this->assertStringContainsString('  Not processed yet; its content is not available.', $prompt);

    $this->assertStringContainsString('- Notes (ID: 3)', $prompt);
    $this->assertStringContainsString('  Processing failed; its content is not available.', $prompt);

    // Never the full text: context_read_document is what reads that.
    $this->assertStringNotContainsString('The full budget text.', $prompt);
  }

}
