<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Workflow\Drafting;

use Drupal\oe_ai_assistant\Neuron\Agent\RouterAgent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\DraftingTurnWorkflow;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Events\ConsolidateEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Events\DraftGroupsEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Events\DraftRequestedEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Events\VersionEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Nodes\ConsolidateNode;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Nodes\DraftGroupsNode;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Nodes\PlanNode;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Nodes\RouteNode;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Nodes\VersionNode;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Events\StartEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the graph of one drafting turn.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\DraftingTurnWorkflow
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
