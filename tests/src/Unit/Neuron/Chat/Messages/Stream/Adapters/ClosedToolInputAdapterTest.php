<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Neuron\Chat\Messages\Stream\Adapters;

use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters\ClosedToolInputAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the adapter that closes a tool call's input.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters\ClosedToolInputAdapter
 */
class ClosedToolInputAdapterTest extends TestCase {

  /**
   * Tests that a call's input is opened, streamed and then closed.
   *
   * A client renders a call once its input is complete, so the closing event
   * is what makes the call appear as it happens rather than at the end of the
   * stream.
   *
   * @covers ::previewTool
   */
  public function testCallClosesItsInput(): void {
    $adapter = new ClosedToolInputAdapter();
    $call = ToolCall::make('draft_group', 'call_1', ['group' => 'main_fields']);

    $types = array_column($this->frames($adapter->transform(new ToolCallChunk('m1', $call))), 'type');
    $this->assertSame([
      'start',
      'tool-input-start',
      'tool-input-delta',
      'tool-input-available',
    ], $types);
  }

  /**
   * Tests that the closing event carries the arguments the call runs with.
   *
   * @covers ::previewTool
   */
  public function testTheClosingEventCarriesTheInputs(): void {
    $adapter = new ClosedToolInputAdapter();
    $call = ToolCall::make('draft_group', 'call_1', ['group' => 'main_fields']);

    $frames = $this->frames($adapter->transform(new ToolCallChunk('m1', $call)));
    $closing = end($frames);

    $this->assertSame('tool-input-available', $closing['type']);
    $this->assertSame('call_1', $closing['toolCallId']);
    $this->assertSame('draft_group', $closing['toolName']);
    $this->assertSame(['group' => 'main_fields'], $closing['input']);
  }

  /**
   * Tests that a result closes the input once, whatever came before it.
   *
   * @covers ::previewTool
   */
  public function testTheInputIsClosedOnlyOnce(): void {
    $adapter = new ClosedToolInputAdapter();
    $call = ToolCall::make('draft_group', 'call_1', ['group' => 'main_fields'])
      ->setResult('{"group":"main_fields"}');

    $this->frames($adapter->transform(new ToolCallChunk('m1', $call)));
    $types = array_column($this->frames($adapter->transform(new ToolResultChunk($call))), 'type');

    $this->assertSame(['tool-output-available'], $types,
      'A call already opened and closed only reports its output.');
  }

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

}
