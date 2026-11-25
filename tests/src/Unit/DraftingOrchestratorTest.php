<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
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
   * Unrelated associative output from a reference group is ignored.
   *
   * This protects the consolidator from assigning a flat main-fields result
   * to an inline entity field, which would later fail without its bundle
   * discriminator.
   */
  public function testRunIgnoresUnwrappedAssociativeReferenceOutput(): void {
    $groups = [
      [
        'groupId' => 'field_content_paragraphs',
        'label' => 'Content paragraphs',
        'fieldNames' => ['field_content_paragraphs'],
        'schemaSlice' => ['type' => 'object'],
      ],
    ];
    $method = new \ReflectionMethod(DraftingOrchestrator::class, 'consolidate');
    $result = $method->invoke(
      new DraftingOrchestrator(
        $this->createMock(DraftingSchemaProviderInterface::class),
        $this->createMock(AiAgentManager::class),
        new NullLogger(),
        $this->createMock(MessageRecorderInterface::class),
      ),
      $groups,
      ['field_content_paragraphs' => ['title' => [['value' => 'Wrong group']]]],
    );

    $this->assertSame([], $result);
  }

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

    $context = new EditorialContext(
      toneId: NULL,
      toneLabel: NULL,
      tonePrompt: NULL,
      templateId: 'my_template',
      templateLabel: NULL,
    );
    $host = $this->createMock(EntityInterface::class);
    $result = $orchestrator->run(
      $stream, [], 'node', 'oe_news', $host, NULL, $context
    );

    $this->assertSame([], $result);
  }

}
