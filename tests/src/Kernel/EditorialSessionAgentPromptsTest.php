<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\AiEditorialContext;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface;

/**
 * Tests the prompt blocks an editorial session agent injects.
 *
 * An agent renders what the editor set up, so the blocks are read off the
 * instructions a built agent carries. The session reads the tone and the
 * documents through two services, which stubs stand in for here.
 *
 * @group oe_ai_assistant
 */
class EditorialSessionAgentPromptsTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests that the prompt block is built from tone label and guidelines.
   */
  public function testTonePromptCarriesLabelAndGuidelines(): void {
    $prompt = $this->instructions([
      'id' => '3',
      'label' => 'Formal',
      'prompt' => 'Use professional, institutional language.',
    ], []);

    $this->assertStringContainsString(
      'Editorial context selected by the editor for this draft:',
      $prompt,
    );
    $this->assertStringContainsString('- Tone: Formal', $prompt);
    $this->assertStringContainsString(
      '- Tone guidelines: Use professional, institutional language.',
      $prompt,
    );
    $this->assertStringContainsString(
      'Follow the tone guidelines',
      $prompt,
    );
  }

  /**
   * Tests that nothing set up adds no block to the instructions.
   */
  public function testNothingSetUpRendersNoBlock(): void {
    $instructions = $this->instructions(NULL, []);

    $this->assertStringContainsString('You are a content generator', $instructions);
    $this->assertStringNotContainsString('Editorial context selected by the editor', $instructions);

    // A tone the editor never wrote guidelines for is nothing to follow.
    $this->assertStringNotContainsString(
      'Editorial context selected by the editor',
      $this->instructions(['id' => '3', 'label' => 'Formal', 'prompt' => ''], []),
    );
  }

  /**
   * Tests that the drafter is handed the tool to read a document in full.
   *
   * Once the text budget is spent, the documents block shows a document as
   * a summary, or as not available at all. The drafter gets
   * context_read_document so it can still get the full text of one it wants
   * to draft from, and the instructions have to name the tool for it to
   * know about it.
   */
  public function testDrafterCanReadAttachedDocumentsInFull(): void {
    $agent = $this->container->get(NeuronAgentManagerInterface::class)->createAgent('group_drafter', [
      'session' => $this->stubbedSession(NULL, [self::document('1', 'done', 'Full text.')]),
      'group' => 'main_fields',
      'schema' => ['type' => 'object'],
      'run' => 'draft_1',
    ]);

    $names = array_map(static fn ($tool) => $tool->getName(), $agent->getTools());
    $this->assertContains('context_read_document', $names);
    $this->assertContains('context_get_session_documents', $names);

    $instructions = $agent->getInstructions()->getContent();
    $this->assertStringContainsString('context_read_document', $instructions);
    $this->assertStringContainsString('context_get_session_documents', $instructions);
    // Hedged, not mandatory: a session with no documents should never be
    // told it must call the tool regardless.
    $this->assertStringContainsString('may have attached', $instructions);
  }

  /**
   * Tests that the drafter's instructions carry no document text.
   *
   * A document's text reaches this agent only through context_read_document;
   * it is never inlined, so the drafter discovers and reads documents purely
   * through tools.
   */
  public function testDrafterInstructionsCarryNoDocumentText(): void {
    $prompt = $this->instructions(NULL, [
      self::document('1', 'done', 'Full text of one.', 'Summary one.'),
    ]);

    $this->assertStringNotContainsString('Full text of one.', $prompt);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules(['oe_ai_assistant_test']);
    // Building an agent resolves a provider for its operation type.
    $this->config('ai.settings')
      ->set('default_providers', [
        'chat_with_structured_response' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
        'chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
      ])
      ->save();
  }

  /**
   * The instructions the drafter is built with, on a stubbed session.
   *
   * The drafter is told the tone and the documents, so both blocks are in what
   * it carries.
   *
   * @param array|null $tone
   *   The tone the session is set up with.
   * @param array $documents
   *   The documents the session is set up with.
   *
   * @return string
   *   The instructions.
   */
  private function instructions(?array $tone, array $documents): string {
    $agent = $this->container->get(NeuronAgentManagerInterface::class)->createAgent('group_drafter', [
      'session' => $this->stubbedSession($tone, $documents),
      'group' => 'main_fields',
      'schema' => ['type' => 'object'],
      'run' => 'draft_1',
    ]);

    return $agent->getInstructions()->getContent();
  }

  /**
   * A session that answers with the tone and the documents given here.
   *
   * @param array|null $tone
   *   The tone the session is set up with.
   * @param array $documents
   *   The documents the session is set up with.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The session.
   */
  private function stubbedSession(?array $tone, array $documents): AiEditorialSessionInterface {
    // The session reads both through the container, and an interface is an
    // alias of its class, so the stubs go in under the class.
    $tones = $this->createMock(AiEditorialContextInterface::class);
    $tones->method('getTone')->willReturn($tone ?? []);
    $this->container->set(AiEditorialContext::class, $tones);
    $repository = $this->createMock(DocumentRepositoryInterface::class);
    $repository->method('describe')->willReturn($documents);
    $this->container->set(ContextDocumentRepository::class, $repository);

    $session = $this->createSession($this->createUser());
    // A tone the session does not reference is never read, so the stub above
    // only answers once the reference is set.
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
