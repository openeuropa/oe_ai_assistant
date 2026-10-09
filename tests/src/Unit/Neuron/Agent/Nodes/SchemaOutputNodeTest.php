<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Agent\Nodes;

use Drupal\oe_ai_assistant\Neuron\Agent\Events\SchemaViolationEvent;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaOutputNode;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaRetryNode;
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
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaOutputNode
 */
class SchemaOutputNodeTest extends TestCase {

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
   * Drives the two nodes the way the agent graph routes between them.
   *
   * A violation event returns to the retry node, which either asks the
   * model again through a fresh inference event or gives up.
   */
  private function runNodes(FakeAIProvider $provider, int $maxRetries = 1): AgentState {
    $state = new AgentState();
    $state->set('__workflowId', 'test');
    $output = new SchemaOutputNode($provider, 'main_fields', self::SCHEMA);
    $retry = new SchemaRetryNode($maxRetries);
    $event = new AIInferenceEvent('Draft the fields.', []);
    $event->setMessages(new UserMessage('Write about broadband.'));

    while (TRUE) {
      $output->setWorkflowContext($state, $event);
      $result = $output($event, $state);
      if ($result instanceof StopEvent) {
        return $state;
      }
      $this->assertInstanceOf(SchemaViolationEvent::class, $result);
      $retry->setWorkflowContext($state, $result);
      $event = $retry($result, $state);
      $this->assertInstanceOf(AIInferenceEvent::class, $event);
    }
  }

  /**
   * @covers ::__invoke
   */
  public function testMatchingAnswerIsDecodedAndSchemaIsQuoted(): void {
    $provider = new FakeAIProvider(new AssistantMessage('{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}'));

    $state = $this->runNodes($provider);

    $this->assertSame(['title' => [['value' => 'T']], 'field_teaser' => [['value' => 'S']]], $state->get(SchemaOutputNode::OUTPUT_KEY));
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

    $state = $this->runNodes($provider);

    $this->assertArrayHasKey('field_teaser', $state->get(SchemaOutputNode::OUTPUT_KEY));
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
  public function testViolationEventCarriesTheValidationErrorsOnly(): void {
    $provider = new FakeAIProvider(new AssistantMessage('{"title": [{"value": "T"}], "body": [{"value": "B"}]}'));
    $state = new AgentState();
    $state->set('__workflowId', 'test');
    $node = new SchemaOutputNode($provider, 'main_fields', self::SCHEMA);
    $event = new AIInferenceEvent('Draft the fields.', []);
    $event->setMessages(new UserMessage('Write about broadband.'));
    $node->setWorkflowContext($state, $event);

    $result = $node($event, $state);

    $this->assertInstanceOf(SchemaViolationEvent::class, $result);
    $this->assertSame('main_fields', $result->schema);
    $this->assertSame($event, $result->inferenceEvent,
      'The inference travels with the violation so the retry can run it again.');
    // The lines are the validator's own, naming the property and what is
    // wrong with it.
    $this->assertSame([
      'field_teaser: The property field_teaser is required',
      'The property body is not defined and the definition does not allow additional properties',
    ], $result->violations);
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
    $this->runNodes($provider);
  }

}
