<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Agent\Nodes;

use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\JsonSchemaOutputNode;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Events\StopEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the node that asks for JSON against a runtime schema.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Agent\Nodes\JsonSchemaOutputNode
 */
class JsonSchemaOutputNodeTest extends TestCase {

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
      'field_teaser' => [
        'type' => 'array',
        'items' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
      ],
    ],
  ];

  /**
   * Runs the node once over the given provider and returns the state.
   */
  private function runNode(FakeAIProvider $provider, int $maxRetries = 1): AgentState {
    $state = new AgentState();
    $state->set('__workflowId', 'test');
    $node = new JsonSchemaOutputNode($provider, 'main_fields', self::SCHEMA, $maxRetries);
    $event = new AIInferenceEvent('Draft the fields.', []);
    $event->setMessages(new UserMessage('Write about broadband.'));
    $node->setWorkflowContext($state, $event);
    $result = $node($event, $state);
    $this->assertInstanceOf(StopEvent::class, $result);
    return $state;
  }

  /**
   * @covers ::__invoke
   */
  public function testMatchingAnswerIsDecodedAndSchemaIsQuoted(): void {
    $provider = new FakeAIProvider(new AssistantMessage('{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}'));

    $state = $this->runNode($provider);

    $this->assertSame(['title' => [['value' => 'T']], 'field_teaser' => [['value' => 'S']]], $state->get(JsonSchemaOutputNode::OUTPUT_KEY));
    $provider->assertCallCount(1);
    $record = $provider->getRecorded()[0];
    $this->assertSame(['title', 'field_teaser'], $record->structuredSchema['required']);
    $this->assertFalse($record->structuredSchema['additionalProperties']);
    $this->assertStringContainsString('Draft the fields.', $record->systemPrompt);
    $this->assertStringContainsString('"field_teaser"', $record->systemPrompt);
  }

  /**
   * @covers ::__invoke
   */
  public function testAnswerWithWrongKeysIsRetriedWithTheViolations(): void {
    $provider = new FakeAIProvider(
      new AssistantMessage('{"title": [{"value": "T"}], "body": [{"value": "B"}]}'),
      new AssistantMessage('{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}'),
    );

    $state = $this->runNode($provider);

    $this->assertArrayHasKey('field_teaser', $state->get(JsonSchemaOutputNode::OUTPUT_KEY));
    $provider->assertCallCount(2);
    $messages = $provider->getRecorded()[1]->messages;
    $correction = end($messages);
    $this->assertStringContainsString('does not match the schema', $correction->getContent());
    $this->assertStringContainsString('field_teaser', $correction->getContent());
    $this->assertStringContainsString('body', $correction->getContent());
  }

  /**
   * @covers ::__invoke
   */
  public function testGivesUpAfterTheAllowedRetries(): void {
    $provider = new FakeAIProvider(
      new AssistantMessage('not json at all'),
      new AssistantMessage('{"body": [{"value": "B"}]}'),
    );

    $this->expectException(AgentException::class);
    $this->expectExceptionMessage('does not match its schema');
    $this->runNode($provider);
  }

}
