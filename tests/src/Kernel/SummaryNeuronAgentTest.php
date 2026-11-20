<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', ['chat' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
      ->save();
    MockAiProvider::reset();
    $this->session = $this->createSession($this->createUser());
  }

  /**
   * Builds the agent with the session context the plugin requires.
   */
  private function agent(): Agent {
    return $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('document_summary', ['session' => $this->session]);
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
   * DocumentExtractionProcessor::summarize() catches this and ends the
   * document in error; this covers what the agent itself does.
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
