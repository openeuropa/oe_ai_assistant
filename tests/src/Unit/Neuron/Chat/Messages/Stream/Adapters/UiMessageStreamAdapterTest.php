<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Chat\Messages\Stream\Adapters;

use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters\UiMessageStreamAdapter;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Chunks\AgentEventChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the UI message stream adapter.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters\UiMessageStreamAdapter
 */
class UiMessageStreamAdapterTest extends TestCase {

  /**
   * Decodes the SSE frames an iterable of output lines carries.
   */
  private function frames(iterable $lines): array {
    $frames = [];
    foreach ($lines as $line) {
      $frames[] = str_starts_with($line, 'data: [DONE]') ? '[DONE]' : json_decode(substr($line, 6), TRUE);
    }
    return $frames;
  }

  /**
   * @covers ::start
   * @covers ::handleText
   * @covers ::end
   */
  public function testTextRunFramesStartDeltasAndFinish(): void {
    $adapter = new UiMessageStreamAdapter();
    [$start] = $this->frames($adapter->start());
    $this->assertSame('start', $start['type']);
    $this->assertStringStartsWith('msg_', $start['messageId']);

    $this->assertSame(
      [['type' => 'text-delta', 'textDelta' => 'Hello']],
      $this->frames($adapter->transform(new TextChunk('m1', 'Hello'))),
    );
    $this->assertSame([], $this->frames($adapter->transform(new TextChunk('m1', ''))));
    $this->assertSame(
      [['type' => 'finish', 'finishReason' => 'stop'], '[DONE]'],
      $this->frames($adapter->end()),
    );
  }

  /**
   * @covers ::handleToolCall
   * @covers ::handleToolResult
   */
  public function testToolCallsKeepTheirOwnIdsAndDecodedResults(): void {
    $adapter = new UiMessageStreamAdapter();
    $this->frames($adapter->start());
    $first = Tool::make('draft_group', 'Drafts.')->setCallId('call_1')->setInputs(['group' => 'main_fields'])->setResult('{"group":"main_fields"}');
    $second = Tool::make('draft_group', 'Drafts.')->setCallId('call_2')->setInputs(['group' => 'field_paragraphs'])->setResult('plain');

    $this->assertSame([
      ['type' => 'tool-call-start', 'toolCallId' => 'call_1', 'toolName' => 'draft_group'],
      ['type' => 'tool-call-delta', 'toolCallId' => 'call_1', 'argsText' => '{"group":"main_fields"}'],
      ['type' => 'tool-call-end', 'toolCallId' => 'call_1'],
    ], $this->frames($adapter->transform(new ToolCallChunk($first))));
    $this->assertSame(
      [['type' => 'tool-result', 'toolCallId' => 'call_1', 'result' => ['group' => 'main_fields']]],
      $this->frames($adapter->transform(new ToolResultChunk($first))),
    );
    $this->assertSame(
      [['type' => 'tool-result', 'toolCallId' => 'call_2', 'result' => ['text' => 'plain']]],
      $this->frames($adapter->transform(new ToolResultChunk($second))),
    );
  }

  /**
   * @covers ::transform
   */
  public function testAgentEventBecomesOneTransientDataPart(): void {
    $adapter = new UiMessageStreamAdapter();
    $chunk = new AgentEventChunk('inference-start', 'drafting', 'model call started', ['role' => 'user']);
    $frames = $this->frames($adapter->transform($chunk));

    $this->assertSame([
      [
        'type' => 'data-agent-event',
        'data' => [
          'event' => 'inference-start',
          'agent' => 'drafting',
          'summary' => 'model call started',
          'payload' => ['role' => 'user'],
        ],
        'transient' => TRUE,
      ],
    ], $frames);
  }

  /**
   * @covers ::error
   */
  public function testErrorFrame(): void {
    $this->assertSame(
      [['type' => 'error', 'errorText' => 'Boom']],
      $this->frames((new UiMessageStreamAdapter())->error('Boom')),
    );
  }

}
