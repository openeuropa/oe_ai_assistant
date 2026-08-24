<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\ai_agents\AiAgentInterface as AiAgentEntityInterface;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Service\DraftingOrchestrator;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for DraftingOrchestrator's schema provider integration.
 */
#[Group('oe_ai_assistant')]
class DraftingOrchestratorTest extends TestCase {

  /**
   * The template id given to run() is passed to the schema provider.
   */
  public function testRunPassesTemplateIdToProvider(): void {
    $provider = $this->createMock(DraftingSchemaProviderInterface::class);
    $provider->expects($this->once())
      ->method('groups')
      ->with('node', 'oe_news', 'my_template')
      ->willReturn([]);

    $stream = $this->createMock(UiMessageStreamInterface::class);
    $stream->expects($this->once())->method('textDelta');

    $orchestrator = new DraftingOrchestrator(
      $provider,
      $this->createMock(AiAgentManager::class),
      new NullLogger(),
      $this->createMock(MessageRecorderInterface::class),
    );

    $host = $this->createMock(EntityInterface::class);
    $result = $orchestrator->run(
      $stream, [], 'node', 'oe_news', $host, NULL, 'my_template'
    );

    $this->assertSame([], $result);
  }

  /**
   * Supporting-document summaries are included in sub-agent task prompts.
   */
  public function testRunPassesSupportingDocumentSummariesToSubAgentTask(): void {
    $provider = $this->createMock(DraftingSchemaProviderInterface::class);
    $provider->expects($this->once())
      ->method('groups')
      ->with('node', 'oe_news', NULL)
      ->willReturn([
        [
          'groupId' => 'main_fields',
          'label' => 'Main fields',
          'schemaSlice' => ['type' => 'object'],
          'fieldNames' => ['title'],
        ],
      ]);

    $agentEntity = $this->createMock(AiAgentEntityInterface::class);
    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('getAiAgentEntity')->willReturn($agentEntity);
    $agent->expects($this->once())
      ->method('setTask')
      ->with($this->callback(function (Task $task): bool {
        $prompt = $task->getDescription();

        return str_contains($prompt, "Supporting document context:\n")
          && str_contains($prompt, 'Do not copy or publish them verbatim.')
          && str_contains($prompt, 'Document 1 - Brief one: First supporting summary.')
          && str_contains($prompt, 'Document 2 - Brief two: Second supporting summary.')
          && !str_contains($prompt, 'private://');
      }));
    $agent->method('determineSolvability')
      ->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('solve')
      ->willReturn('{"title": [{"value": "Generated title"}]}');

    $manager = $this->createMock(AiAgentManager::class);
    $manager->expects($this->once())
      ->method('createInstance')
      ->with('oe_content_drafter')
      ->willReturn($agent);

    $stream = $this->createMock(UiMessageStreamInterface::class);
    $stream->method('extractJson')
      ->willReturn(['title' => [['value' => 'Generated title']]]);

    $orchestrator = new DraftingOrchestrator(
      $provider,
      $manager,
      new NullLogger(),
      $this->createMock(MessageRecorderInterface::class),
    );

    $result = $orchestrator->run(
      $stream,
      [new ChatMessage('user', 'Use the uploaded context.')],
      'node',
      'oe_news',
      $this->createMock(EntityInterface::class),
      NULL,
      NULL,
      [
        [
          'label' => 'Brief one',
          'summary' => 'First supporting summary.',
        ],
        [
          'label' => 'Brief two',
          'summary' => 'Second supporting summary.',
        ],
      ],
    );

    $this->assertSame(['title' => [['value' => 'Generated title']]], $result);
  }

}
