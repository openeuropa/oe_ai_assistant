<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Agent;

use Drupal\oe_ai_assistant\Neuron\Agent\FieldGroupAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent that drafts one field group.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Agent\FieldGroupAgent
 */
class FieldGroupAgentTest extends TestCase {

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
   * @covers ::structured
   */
  public function testRetriesUntilTheAnswerMatches(): void {
    $provider = new FakeAIProvider(
      $this->rejected(),
      $this->rejected(),
      new AssistantMessage('{"title": [{"value": "T"}]}'),
    );
    $agent = new FieldGroupAgent($provider, '', 'main_fields', self::SCHEMA);

    $fields = $agent->structured(new UserMessage('Write about broadband.'));

    $this->assertSame(['title' => [['value' => 'T']]], $fields);
    $provider->assertCallCount(3, 'Two rejected answers are corrected before the third matches.');
  }

  /**
   * @covers ::structured
   */
  public function testGivesUpAfterItsOwnAllowance(): void {
    $provider = new FakeAIProvider(...array_fill(0, FieldGroupAgent::MAX_SCHEMA_RETRIES + 1, $this->rejected()));
    $agent = new FieldGroupAgent($provider, '', 'main_fields', self::SCHEMA);

    try {
      $agent->structured(new UserMessage('Write about broadband.'));
      $this->fail('The run must fail once the corrections are spent.');
    }
    catch (AgentException $e) {
      $this->assertStringContainsString('does not match its schema', $e->getMessage());
    }
    $provider->assertCallCount(FieldGroupAgent::MAX_SCHEMA_RETRIES + 1,
      'One first answer plus one call per allowed correction.');
  }

}
