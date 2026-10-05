<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Agent;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowResources;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaOutputNode;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaRetryNode;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Nodes\AgentStartNode;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the drafter graph the field group plugin declares.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaOutputNode
 */
class DrafterGraphTest extends TestCase {

  /**
   * How many corrections the tests allow, as the turn service does.
   */
  private const MAX_RETRIES = 5;

  /**
   * A main fields slice as the schema composer produces it.
   */
  private const SCHEMA = [
    'type' => 'object',
    'properties' => [
      'title' => [
        'type' => 'array',
        'items' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
      ],
    ],
  ];

  /**
   * An answer the schema rejects, since it names a property it forbids.
   */
  private function rejected(): AssistantMessage {
    return new AssistantMessage('{"title": [{"value": "T"}], "hallucinated": true}');
  }

  /**
   * Builds the drafter the way the field group plugin does.
   */
  private function drafter(FakeAIProvider $provider): Workflow {
    $resources = new AgentResources(
      $provider,
      new ChatHistory(new InMemoryMessageStore(), 'field_group.session-1.2.main_fields'),
      new SystemMessage('Write the group.'),
    );
    $workflow = Workflow::make('field_group.session-1.2.main_fields', new AgentState());
    $workflow
      ->addNodes([
        new AgentStartNode(),
        new SchemaOutputNode('main_fields', self::SCHEMA),
        new SchemaRetryNode(),
        new AgentEndNode(),
      ])
      ->setStartEvent(new AgentStartEvent(
        [new UserMessage('Write about broadband.')],
        new AgentRunOptions(outputClass: 'main_fields', maxRetries: self::MAX_RETRIES),
      ))
      ->setResources(static fn (): WorkflowResources => $resources);

    return $workflow;
  }

  /**
   * @covers ::__invoke
   */
  public function testRetriesUntilTheAnswerMatches(): void {
    $provider = new FakeAIProvider(
      $this->rejected(),
      $this->rejected(),
      new AssistantMessage('{"title": [{"value": "T"}]}'),
    );

    $fields = $this->drafter($provider)->run()->get(SchemaOutputNode::OUTPUT_KEY);

    $this->assertSame(['title' => [['value' => 'T']]], $fields);
    $provider->assertCallCount(3);
  }

  /**
   * @covers ::__invoke
   */
  public function testGivesUpAfterItsOwnAllowance(): void {
    // One message instance per answer: the history holds what it is given,
    // and a shared instance would be the same turn recorded many times.
    $provider = new FakeAIProvider(...array_map(
      fn (): AssistantMessage => $this->rejected(),
      range(0, self::MAX_RETRIES),
    ));

    try {
      $this->drafter($provider)->run();
      $this->fail('The run must fail once the corrections are spent.');
    }
    catch (AgentException $e) {
      $this->assertStringContainsString('does not match its schema', $e->getMessage());
    }
    $provider->assertCallCount(self::MAX_RETRIES + 1);
  }

}
