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
 * Tests the sub-agent that answers one question from the session's state.
 *
 * The session facts themselves (tone, template, documents) are covered by
 * EditorialContextToolsTest, against the tools this agent calls; this file
 * covers the agent's own wiring to those tools and the tool-call round trip.
 *
 * @group oe_ai_assistant
 */
class GetEditorialContextNeuronAgentTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', ['chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
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
   * Tests that the agent declares the three session-info tools.
   */
  public function testAgentDeclaresTheThreeSessionTools(): void {
    $session = $this->createSession($this->createUser());

    $agent = $this->agent($session);

    $names = array_map(static fn ($tool) => $tool->getName(), $agent->getTools());
    $this->assertContains('context_get_tone_options', $names);
    $this->assertContains('context_get_template_options', $names);
    $this->assertContains('context_get_session_documents', $names);

    $instructions = $agent->getInstructions()->getContent();
    $this->assertStringContainsString('context_get_tone_options', $instructions);
    $this->assertStringContainsString('context_get_template_options', $instructions);
    $this->assertStringContainsString('context_get_session_documents', $instructions);
  }

  /**
   * Tests that the agent answers after calling one of its tools.
   */
  public function testAgentAnswersAfterToolCall(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'context_get_tone_options', 'arguments' => '{}'],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'No tone is selected yet.'));

    $message = $this->agent($session)->chat(new UserMessage('What tone is set?'))->getMessage();

    $this->assertSame('No tone is selected yet.', $message->getContent());
  }

}
