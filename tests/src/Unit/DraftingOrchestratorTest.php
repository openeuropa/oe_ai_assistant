<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
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

}
