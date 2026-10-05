<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Chat\Messages\Stream\Adapters;

use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters\UiMessageStreamAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the UI message stream adapter.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters\UiMessageStreamAdapter
 */
class UiMessageStreamAdapterTest extends TestCase {

  /**
   * Renders protocol events as the arrays the wire carries.
   */
  private function frames(iterable $events): array {
    $frames = [];
    foreach ($events as $event) {
      assert($event instanceof ProtocolEvent);
      $frames[] = json_decode((string) json_encode($event), TRUE);
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
      [['type' => 'finish', 'finishReason' => 'stop']],
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
    $first = ToolCall::make('draft_group', 'call_1', ['group' => 'main_fields'])->setResult('{"group":"main_fields"}');
    $second = ToolCall::make('draft_group', 'call_2', ['group' => 'field_paragraphs'])->setResult('plain');

    $this->assertSame([
      ['type' => 'tool-call-start', 'toolCallId' => 'call_1', 'toolName' => 'draft_group'],
      ['type' => 'tool-call-delta', 'toolCallId' => 'call_1', 'argsText' => '{"group":"main_fields"}'],
      ['type' => 'tool-call-end', 'toolCallId' => 'call_1'],
    ], $this->frames($adapter->transform(new ToolCallChunk('m1', $first))));
    $this->assertSame(
      [['type' => 'tool-result', 'toolCallId' => 'call_1', 'result' => ['group' => 'main_fields']]],
      $this->frames($adapter->transform(new ToolResultChunk($first))),
    );
    // A result arriving without its call having streamed opens the call
    // first, so the app has a part to attach the result to.
    $this->assertSame([
      ['type' => 'tool-call-start', 'toolCallId' => 'call_2', 'toolName' => 'draft_group'],
      ['type' => 'tool-call-delta', 'toolCallId' => 'call_2', 'argsText' => '{"group":"field_paragraphs"}'],
      ['type' => 'tool-call-end', 'toolCallId' => 'call_2'],
      ['type' => 'tool-result', 'toolCallId' => 'call_2', 'result' => ['text' => 'plain']],
    ], $this->frames($adapter->transform(new ToolResultChunk($second))));
  }

  /**
   * The failure the editor reads says nothing about the exception.
   */
  public function testErrorFrameDoesNotLeakTheException(): void {
    $frames = $this->frames((new UiMessageStreamAdapter())->error(new \RuntimeException('Boom')));

    $this->assertSame('error', $frames[0]['type']);
    $this->assertStringNotContainsString('Boom', $frames[0]['errorText']);
  }

}
