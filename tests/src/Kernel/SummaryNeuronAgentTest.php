<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Tests the agent that summarises an extracted document.
 *
 * DocumentExtractionProcessor builds the text this agent summarises, but
 * these tests exercise the agent on its own: what it sends the provider
 * and what it does with a provider failure.
 *
 * @group oe_ai_assistant
 */
class SummaryNeuronAgentTest extends AiEditorialSessionKernelTestBase {

  /**
   * The session the agent runs under.
   */
  private AiEditorialSessionInterface $session;

  /**
   * The document the agent summarises.
   */
  private MediaInterface $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('file', ['file_usage']);
    $this->enableModules(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', ['chat' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
      ->save();
    MockAiProvider::reset();
    $this->session = $this->createSession($this->createUser());
    $this->media = $this->createDocument();
  }

  /**
   * Creates a context document attached to the session.
   */
  private function createDocument(string $name = 'brief.txt'): MediaInterface {
    file_put_contents('public://' . $name, 'payload');
    $file = File::create(['uri' => 'public://' . $name, 'filename' => $name, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'ai_context_document',
      'name' => $name,
      'status' => 0,
      'oe_ai_context_document' => ['target_id' => $file->id()],
      'oe_ai_session' => ['target_id' => $this->session->id()],
    ]);
    $media->save();

    return $media;
  }

  /**
   * Builds the agent with the session and document context the plugin needs.
   */
  private function agent(): Agent {
    return $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('document_summary', ['session' => $this->session, 'media' => $this->media]);
  }

  /**
   * Tests that the agent sends the document and answers the summary.
   */
  public function testSummaryAgentWritesBriefSummary(): void {
    MockAiProvider::enqueue(new MockResponse('A brief summary.'));

    $state = $this->agent()->chat(new UserMessage('Full text of the document.'));

    $this->assertSame('A brief summary.', $state->getMessage()?->getContent());

    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log);
    $this->assertStringContainsString('Full text of the document.', $log[0]['messages'][0]['text']);
    $this->assertStringContainsString('summary', strtolower($log[0]['system_prompt']));
  }

  /**
   * Tests that a provider failure reaches the caller rather than being hidden.
   *
   * The caller turns this into an error state for the document. This test
   * covers only what the agent itself does.
   */
  public function testProviderFailureReachesTheCaller(): void {
    MockAiProvider::enqueue(new MockResponse(error: new \RuntimeException('Provider down.')));

    $agent = $this->agent();

    $thrown = NULL;
    try {
      $agent->chat(new UserMessage('Full text of the document.'));
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }

    $this->assertNotNull($thrown, 'The provider failure is not swallowed.');
    $this->assertStringContainsString('Provider down.', $thrown->getMessage());
  }

}
