<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\AiEditorialContext;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Log\AbstractLogger;

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
   *
   * We assert on the tool's own JSON in the second provider call, not just on
   * the final answer: the mock returns that answer either way, and
   * toolErrorHandler() leaves a JSON tool result behind even when the tool
   * threw. The payload tells a working call from a swallowed failure.
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

    $log = MockAiProvider::getCallLog();
    $toolResultText = implode("\n", array_column($log[1]['messages'], 'text'));
    $this->assertStringContainsString('"selected":null', $toolResultText);
    $this->assertStringContainsString('"options"', $toolResultText);
  }

  /**
   * Tests that a tool failure is logged in full and not leaked to the editor.
   *
   * A deleted tone term, say, makes context_get_tone_options throw, and the
   * message names the term ID and the vocabulary. The agent repeats to the
   * editor whatever a tool returns, so toolErrorHandler() keeps that message
   * in the log and hands the model "The tool failed." instead.
   */
  public function testToolFailureIsLoggedAndNotLeakedToTheEditor(): void {
    $records = [];
    $this->container->get('logger.factory')->addLogger(new class ($records) extends AbstractLogger {

      /**
       * @param array $records
       *   The records, by reference, so the test reads what was logged.
       */
      public function __construct(
        private array &$records,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $placeholders = array_filter(
          $context,
          static fn (mixed $value, string $key): bool => str_starts_with($key, '@') && is_scalar($value),
          ARRAY_FILTER_USE_BOTH,
        );
        $this->records[] = strtr((string) $message, $placeholders);
      }

    });

    $editorialContext = $this->createMock(AiEditorialContextInterface::class);
    $editorialContext->method('getToneOptions')
      ->willThrowException(new \RuntimeException('Taxonomy term "7" does not exist in vocabulary "oe_ai_tone".'));
    $this->container->set(AiEditorialContext::class, $editorialContext);

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
    MockAiProvider::enqueue(new MockResponse(text: 'Something went wrong.'));

    $message = $this->agent($session)->chat(new UserMessage('What tone is set?'))->getMessage();

    $this->assertSame('Something went wrong.', $message->getContent());

    $log = MockAiProvider::getCallLog();
    $toolResultText = implode("\n", array_column($log[1]['messages'], 'text'));
    $this->assertStringNotContainsString('Taxonomy term', $toolResultText);
    $this->assertStringContainsString('The tool failed', $toolResultText);

    $this->assertNotEmpty(preg_grep('@Taxonomy term@', $records));
  }

}
