<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\ai_agents\AiAgentInterface as AiAgentEntityInterface;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Service\DraftingOrchestrator;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for how DraftingOrchestrator reports failed schema groups.
 *
 * A failing provider call does not surface as an exception: ai_agents catches
 * it and returns JOB_NOT_SOLVABLE. These tests pin that such a group is
 * reported as an error rather than silently marked done with no fields.
 */
#[Group('oe_ai_assistant')]
class DraftingOrchestratorFailureTest extends TestCase {

  /**
   * Tests that a non-solvable verdict is recorded as an error turn.
   */
  public function testUnsolvableGroupIsRecordedAsError(): void {
    $recorder = $this->createMock(MessageRecorderInterface::class);
    $host = $this->createMock(EntityInterface::class);
    $parent = $this->createMock(AiConversationMessageInterface::class);

    // The failure is recorded under the draft_content turn, tagged with the
    // schema group that failed.
    $recorder->expects($this->once())
      ->method('recordError')
      ->with(
        $host,
        $this->stringContains('no answer'),
        'main_fields',
        $parent,
      )
      ->willReturn($this->createMock(AiConversationMessageInterface::class));

    $stream = $this->createMock(UiMessageStreamInterface::class);
    $stream->expects($this->once())->method('error');

    // The failure must also land in the log, matching what the main tool
    // loop gets from the respond() catch-all.
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        'Sub-agent @step failed: @error',
        $this->callback(fn(array $context): bool => $context['@step'] === 'main_fields'),
      );

    $plans = [];
    $stream->method('customEvent')
      ->willReturnCallback(function (string $name, $data) use (&$plans): void {
        if ($name === 'data-plan') {
          $plans[] = $data;
        }
      });

    $orchestrator = $this->orchestrator(
      $recorder,
      $stream,
      AiAgentInterface::JOB_NOT_SOLVABLE,
      logger: $logger,
    );
    $result = $orchestrator->run(
      $stream, [], 'node', 'oe_news', $host, $parent,
    );

    $this->assertSame([], $result, 'A failed group contributes no fields.');
    $final = end($plans);
    $this->assertSame('error', $final[0]['status'],
      'The failed group is reported as error, not done.');
  }

  /**
   * Tests that a solvable agent returning an empty answer is an error.
   */
  public function testEmptyResponseIsRecordedAsError(): void {
    $recorder = $this->createMock(MessageRecorderInterface::class);
    $recorder->expects($this->once())
      ->method('recordError')
      ->with(
        $this->anything(),
        $this->stringContains('empty response'),
        'main_fields',
        $this->anything(),
      )
      ->willReturn($this->createMock(AiConversationMessageInterface::class));

    $stream = $this->createMock(UiMessageStreamInterface::class);
    $stream->expects($this->once())->method('error');

    $orchestrator = $this->orchestrator(
      $recorder,
      $stream,
      AiAgentInterface::JOB_SOLVABLE,
      '',
    );
    $orchestrator->run(
      $stream, [], 'node', 'oe_news',
      $this->createMock(EntityInterface::class),
      $this->createMock(AiConversationMessageInterface::class),
    );
  }

  /**
   * Tests that a response that does not parse as JSON is an error.
   */
  public function testInvalidJsonIsRecordedAsError(): void {
    $recorder = $this->createMock(MessageRecorderInterface::class);
    $recorder->expects($this->once())
      ->method('recordError')
      ->with(
        $this->anything(),
        $this->stringContains('not valid JSON'),
        'main_fields',
        $this->anything(),
      )
      ->willReturn($this->createMock(AiConversationMessageInterface::class));

    $stream = $this->createMock(UiMessageStreamInterface::class);
    $stream->expects($this->once())->method('error');
    // extractJson() returning NULL is the mock default; make it explicit.
    $stream->method('extractJson')->willReturn(NULL);

    $orchestrator = $this->orchestrator(
      $recorder,
      $stream,
      AiAgentInterface::JOB_SOLVABLE,
      'The model apologised in prose instead of returning JSON.',
    );
    $orchestrator->run(
      $stream, [], 'node', 'oe_news',
      $this->createMock(EntityInterface::class),
      $this->createMock(AiConversationMessageInterface::class),
    );
  }

  /**
   * Tests that a failure with no parent turn is not recorded as a message.
   *
   * With no parent, no sub-agent transcript is recorded at all (the tagging
   * in runSubAgent() is skipped), so recording the error would create a
   * dangling root-level row in the conversation tree. The failure must still
   * be streamed and reflected in the plan.
   */
  public function testParentlessFailureIsNotRecorded(): void {
    $recorder = $this->createMock(MessageRecorderInterface::class);
    $recorder->expects($this->never())->method('recordError');

    $stream = $this->createMock(UiMessageStreamInterface::class);
    $stream->expects($this->once())->method('error');

    $plans = [];
    $stream->method('customEvent')
      ->willReturnCallback(function (string $name, $data) use (&$plans): void {
        if ($name === 'data-plan') {
          $plans[] = $data;
        }
      });

    $orchestrator = $this->orchestrator(
      $recorder,
      $stream,
      AiAgentInterface::JOB_NOT_SOLVABLE,
    );
    $orchestrator->run(
      $stream, [], 'node', 'oe_news',
      $this->createMock(EntityInterface::class),
      NULL,
    );

    $final = end($plans);
    $this->assertSame('error', $final[0]['status'],
      'The failure is still reported in the plan without a parent.');
  }

  /**
   * Builds an orchestrator whose single sub-agent behaves as configured.
   *
   * @param \Drupal\oe_ai_assistant\Service\MessageRecorderInterface $recorder
   *   The message recorder to assert against.
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The stream to assert against.
   * @param int $solvability
   *   The verdict the stubbed agent returns.
   * @param string $answer
   *   The text the stubbed agent resolves to when solvable.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   A logger to assert against, or NULL for a plain mock.
   *
   * @return \Drupal\oe_ai_assistant\Service\DraftingOrchestrator
   *   The orchestrator under test, wired to a single "main_fields" group.
   */
  private function orchestrator(
    MessageRecorderInterface $recorder,
    UiMessageStreamInterface $stream,
    int $solvability,
    string $answer = '',
    ?LoggerInterface $logger = NULL,
  ): DraftingOrchestrator {
    $provider = $this->createMock(DraftingSchemaProviderInterface::class);
    $provider->method('groups')->willReturn([
      [
        'groupId' => 'main_fields',
        'label' => 'Main fields',
        'schemaSlice' => ['type' => 'object'],
        'fieldNames' => ['title'],
      ],
    ]);

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('getAiAgentEntity')
      ->willReturn($this->createMock(AiAgentEntityInterface::class));
    $agent->method('determineSolvability')->willReturn($solvability);
    $agent->method('solve')->willReturn($answer);

    $manager = $this->createMock(AiAgentManager::class);
    $manager->method('createInstance')->willReturn($agent);

    return new DraftingOrchestrator(
      $provider,
      $manager,
      $logger ?? $this->createMock(LoggerInterface::class),
      $recorder,
    );
  }

}
