<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\ai_agents\AiAgentInterface as AiAgentEntityInterface;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Service\DraftingOrchestrator;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\oe_ai_assistant\Service\StructuredOutputValidator;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for re-asking a sub-agent whose answer breaks its schema.
 */
#[Group('oe_ai_assistant')]
class DraftingOrchestratorRetryTest extends TestCase {

  /**
   * The task prompt each sub-agent attempt received, in order.
   */
  private array $prompts = [];

  /**
   * An answer whose title is a string where the schema wants a list.
   */
  private const INVALID_ANSWER = '{"title": "A headline"}';

  /**
   * An answer that satisfies the schema.
   */
  private const VALID_ANSWER = '{"title": [{"value": "A headline"}]}';

  /**
   * Tests that a valid answer is accepted without a second attempt.
   */
  public function testValidAnswerIsNotRetried(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('warning');

    $result = $this->draft([self::VALID_ANSWER], logger: $logger);

    $this->assertSame([['value' => 'A headline']], $result['title']);
    $this->assertCount(1, $this->prompts, 'One attempt is enough.');
  }

  /**
   * Tests that a broken answer is retried with the schema problem quoted.
   */
  public function testInvalidAnswerIsRetriedWithItsSchemaProblem(): void {
    $result = $this->draft([self::INVALID_ANSWER, self::VALID_ANSWER]);

    $this->assertSame([['value' => 'A headline']], $result['title'],
      'The corrected answer is the one that reaches the draft.');
    $this->assertCount(2, $this->prompts);

    $retry = $this->prompts[1];
    $this->assertStringContainsString('did not match the schema', $retry);
    $this->assertStringContainsString('title', $retry);
    $this->assertStringContainsString('array', $retry,
      'The model is told what the schema expected, in the schema vocabulary.');
    $this->assertStringNotContainsString('Exception', $retry,
      'The model is never handed a PHP error.');
  }

  /**
   * Tests that an answer that is not JSON at all is retried too.
   */
  public function testNonJsonAnswerIsRetried(): void {
    $result = $this->draft(['I am afraid I cannot do that.', self::VALID_ANSWER]);

    $this->assertSame([['value' => 'A headline']], $result['title']);
    $this->assertStringContainsString('not valid JSON', $this->prompts[1]);
  }

  /**
   * Tests that every failed attempt is logged with its number.
   */
  public function testEachFailedAttemptIsLogged(): void {
    $attempts = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('warning')
      ->willReturnCallback(function (string $message, array $context) use (&$attempts): void {
        $attempts[] = $context['@attempt'];
      });

    $this->draft([self::INVALID_ANSWER, self::INVALID_ANSWER, self::VALID_ANSWER], logger: $logger);

    $this->assertSame([1, 2], $attempts);
  }

  /**
   * Tests that the group gives up after five attempts and reports why.
   */
  public function testGroupFailsAfterFiveAttempts(): void {
    $recorder = $this->createMock(MessageRecorderInterface::class);
    $recorder->expects($this->once())
      ->method('recordError')
      ->with(
        $this->anything(),
        $this->stringContains('in 5 attempts'),
        'main_fields',
        $this->anything(),
      )
      ->willReturn($this->createMock(AiConversationMessageInterface::class));

    $result = $this->draft(array_fill(0, 5, self::INVALID_ANSWER), recorder: $recorder);

    $this->assertSame([], $result, 'A group that never matched contributes nothing.');
    $this->assertCount(5, $this->prompts, 'The model is asked five times, not more.');
  }

  /**
   * Runs one drafting pass whose sub-agent answers as given.
   *
   * @param array $answers
   *   The answer text of each successive attempt.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   A logger to assert against, or NULL to discard the log.
   * @param \Drupal\oe_ai_assistant\Service\MessageRecorderInterface|null $recorder
   *   A recorder to assert against, or NULL for a plain mock.
   *
   * @return array
   *   The consolidated fields the pass produced.
   */
  private function draft(array $answers, ?LoggerInterface $logger = NULL, ?MessageRecorderInterface $recorder = NULL): array {
    $provider = $this->createMock(DraftingSchemaProviderInterface::class);
    $provider->method('groups')->willReturn([
      [
        'groupId' => 'main_fields',
        'label' => 'Main fields',
        'fieldNames' => ['title'],
        'schemaSlice' => [
          'type' => 'object',
          'properties' => [
            'title' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => ['value' => ['type' => 'string']],
                'required' => ['value'],
              ],
            ],
          ],
          'required' => ['title'],
        ],
      ],
    ]);

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('getAiAgentEntity')
      ->willReturn($this->createMock(AiAgentEntityInterface::class));
    $agent->method('determineSolvability')
      ->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('setTask')
      ->willReturnCallback(function (Task $task): void {
        $this->prompts[] = $task->getDescription();
      });
    $agent->method('solve')->willReturnOnConsecutiveCalls(...$answers);

    $manager = $this->createMock(AiAgentManager::class);
    $manager->method('createInstance')->willReturn($agent);

    $stream = $this->createMock(UiMessageStreamInterface::class);
    $stream->method('extractJson')->willReturnCallback(
      static function (string $text): ?array {
        $decoded = json_decode($text, TRUE);
        return is_array($decoded) ? $decoded : NULL;
      },
    );

    $orchestrator = new DraftingOrchestrator(
      $provider,
      $manager,
      $logger ?? new NullLogger(),
      $recorder ?? $this->createMock(MessageRecorderInterface::class),
      new StructuredOutputValidator(),
    );

    return $orchestrator->run(
      $stream,
      [],
      'node',
      'oe_news',
      $this->createMock(EntityInterface::class),
      $this->createMock(AiConversationMessageInterface::class),
    );
  }

}
