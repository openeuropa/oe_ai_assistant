<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron;

use Drupal\oe_ai_assistant\Neuron\Drafting\ConsolidateEvent;
use Drupal\oe_ai_assistant\Neuron\Drafting\ConsolidateNode;
use Drupal\oe_ai_assistant\Neuron\Drafting\DraftGroupsEvent;
use Drupal\oe_ai_assistant\Neuron\Drafting\DraftGroupsNode;
use Drupal\oe_ai_assistant\Neuron\Drafting\DraftingTurnWorkflow;
use Drupal\oe_ai_assistant\Neuron\Drafting\DraftRequestedEvent;
use Drupal\oe_ai_assistant\Neuron\Drafting\PlanNode;
use Drupal\oe_ai_assistant\Neuron\Drafting\RouteNode;
use Drupal\oe_ai_assistant\Neuron\Drafting\VersionEvent;
use Drupal\oe_ai_assistant\Neuron\Drafting\VersionNode;
use Drupal\oe_ai_assistant\Neuron\RouterAgent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Events\StartEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the graph of one drafting turn.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Drafting\DraftingTurnWorkflow
 */
class DraftingTurnWorkflowTest extends TestCase {

  /**
   * @covers ::nodes
   */
  public function testTurnIsOneGraphFromRoutingToVersioning(): void {
    $workflow = new DraftingTurnWorkflow(
      new RouterAgent(new FakeAIProvider(), [], 'x'),
      'Draft it.',
      [],
      fn () => [],
      fn () => [],
      fn () => NULL,
    );

    $map = array_map(static fn (object $node): string => $node::class, $workflow->bootstrap()->getEventNodeMap());

    $this->assertSame([
      StartEvent::class => RouteNode::class,
      DraftRequestedEvent::class => PlanNode::class,
      DraftGroupsEvent::class => DraftGroupsNode::class,
      ConsolidateEvent::class => ConsolidateNode::class,
      VersionEvent::class => VersionNode::class,
    ], $map);
    $this->assertSame('stop', $workflow->finishReason());
  }

}
