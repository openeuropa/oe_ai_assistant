<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\oe_ai_assistant\Service\ToolExecutionLoop;
use Drupal\oe_ai_assistant\Service\ToolExecutorInterface;
use Drupal\oe_ai_assistant\Service\ToolLoopResult;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ToolExecutionLoop.
 *
 * Tests the LLM tool loop logic with mock providers and streams.
 * Each test creates a mock provider that returns predetermined
 * responses and verifies the loop behavior (iteration count,
 * terminal tool detection, tool execution, history building).
 */
#[Group('oe_ai_assistant')]
class ToolExecutionLoopTest extends TestCase {

  /**
   * The service under test.
   */
  private ToolExecutionLoop $loop;

  /**
   * Mock tool executor.
   */
  private ToolExecutorInterface $toolExecutor;

  /**
   * Mock SSE stream.
   */
  private UiMessageStreamInterface $stream;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->toolExecutor = $this->createMock(ToolExecutorInterface::class);
    $this->stream = $this->createMock(UiMessageStreamInterface::class);
    $this->loop = new ToolExecutionLoop($this->toolExecutor);
  }

  /**
   * Tests that a text response (no tool calls) ends the loop.
   */
  public function testTextResponseEndsLoop(): void {
    // Configure stream to return no tool calls.
    $this->stream->method('streamChatOutput')->willReturn([]);

    // Mock provider returns text.
    $chatOutput = $this->createMockChatOutput('Hello, how can I help?');
    $provider = $this->createMockProvider([$chatOutput]);

    $history = [new ChatMessage('user', 'Hi')];

    $result = $this->loop->run(
      $provider, 'gpt-4o', 'System prompt.', [],
      $history, $this->stream,
    );

    $this->assertEquals('stop', $result->finishReason);
    $this->assertFalse($result->hasTerminalTool());
    $this->assertTrue($result->hasText());
    $this->assertEquals('Hello, how can I help?', $result->responseText);
    // History should have the assistant response added.
    $this->assertCount(2, $history);
    $this->assertEquals('assistant', $history[1]->getRole());
  }

  /**
   * Tests that a terminal tool call ends the loop.
   */
  public function testTerminalToolEndsLoop(): void {
    // Configure stream to return a draft_content tool call.
    $toolCall = $this->createMockToolCall('draft_content', 'call_1');
    $this->stream->method('streamChatOutput')->willReturn([$toolCall]);

    $provider = $this->createMockProvider([$this->createMockChatOutput('')]);
    $history = [new ChatMessage('user', 'Draft it')];

    $result = $this->loop->run(
      $provider, 'gpt-4o', 'System prompt.', [],
      $history, $this->stream,
    );

    $this->assertEquals('tool_calls', $result->finishReason);
    $this->assertTrue($result->hasTerminalTool());
    $this->assertEquals('draft_content', $result->terminalToolName);
    // History should NOT have the tool result added (terminal
    // tools are handled by the caller, not the loop).
    $this->assertCount(1, $history);
  }

  /**
   * Tests that non-terminal tools are executed and loop continues.
   */
  public function testNonTerminalToolExecutedAndLoopContinues(): void {
    // First call: returns get_content_schema tool call.
    $toolCall = $this->createMockToolCall('get_content_schema', 'call_1');

    // Configure stream: first call returns tool, second returns text.
    $this->stream->method('streamChatOutput')
      ->willReturnOnConsecutiveCalls([$toolCall], []);

    // Mock tool executor returns schema groups.
    $this->toolExecutor->method('execute')
      ->willReturn('{"groups": [{"groupId": "main_fields"}]}');

    // Two chat outputs: one for tool call, one for text.
    $provider = $this->createMockProvider([
      $this->createMockChatOutput(''),
      $this->createMockChatOutput('I see the schema has 3 groups.'),
    ]);

    $history = [new ChatMessage('user', 'Draft news')];

    $result = $this->loop->run(
      $provider, 'gpt-4o', 'System prompt.', [],
      $history, $this->stream,
    );

    $this->assertEquals('stop', $result->finishReason);
    $this->assertEquals(
      'I see the schema has 3 groups.',
      $result->responseText,
    );
    // History: user + assistant(tool call) + tool(result) + assistant(text).
    $this->assertCount(4, $history);
    $this->assertEquals('assistant', $history[1]->getRole());
    $this->assertEquals('tool', $history[2]->getRole());
    $this->assertEquals('assistant', $history[3]->getRole());
  }

  /**
   * Tests that custom terminal tool names are respected.
   */
  public function testCustomTerminalToolNames(): void {
    $toolCall = $this->createMockToolCall('my_custom_tool', 'call_1');
    $this->stream->method('streamChatOutput')->willReturn([$toolCall]);

    $provider = $this->createMockProvider([$this->createMockChatOutput('')]);
    $history = [new ChatMessage('user', 'Do something')];

    $result = $this->loop->run(
      $provider, 'gpt-4o', 'System prompt.', [],
      $history, $this->stream,
      terminalToolNames: ['my_custom_tool'],
    );

    $this->assertEquals('tool_calls', $result->finishReason);
    $this->assertEquals('my_custom_tool', $result->terminalToolName);
  }

  /**
   * Tests ToolLoopResult value object.
   */
  public function testToolLoopResultValueObject(): void {
    $textResult = new ToolLoopResult('stop', '', 'Hello');
    $this->assertFalse($textResult->hasTerminalTool());
    $this->assertTrue($textResult->hasText());

    $toolResult = new ToolLoopResult('tool_calls', 'draft_content');
    $this->assertTrue($toolResult->hasTerminalTool());
    $this->assertFalse($toolResult->hasText());

    $maxResult = new ToolLoopResult('max_iterations');
    $this->assertFalse($maxResult->hasTerminalTool());
    $this->assertFalse($maxResult->hasText());
  }

  // -- Test helpers ---------------------------------------------------

  /**
   * Creates a mock provider that returns chat outputs in sequence.
   */
  private function createMockProvider(array $outputs): object {
    $callIndex = 0;
    $provider = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['chat'])
      ->getMock();
    $provider->method('chat')
      ->willReturnCallback(function () use (&$callIndex, $outputs) {
        return $outputs[$callIndex++];
      });
    return $provider;
  }

  /**
   * Creates a mock ChatOutput with a text response.
   *
   * The ToolExecutionLoop calls extractResponseText() which does:
   * chatOutput->getNormalized() (StreamedChatMessageIteratorInterface)
   * ->reconstructChatOutput() (ChatOutput)
   * ->getNormalized() (ChatMessage)
   * ->getText()
   */
  private function createMockChatOutput(string $text): ChatOutput {
    // The innermost ChatMessage with getText().
    $innerMessage = new ChatMessage('assistant', $text);

    // The reconstructed ChatOutput wrapping the message.
    $reconstructedOutput = $this->createMock(ChatOutput::class);
    $reconstructedOutput->method('getNormalized')
      ->willReturn($innerMessage);

    // The streamed iterator that reconstructs into the output.
    $iterator = $this->createMock(StreamedChatMessageIteratorInterface::class);
    $iterator->method('reconstructChatOutput')
      ->willReturn($reconstructedOutput);

    // The top-level ChatOutput wrapping the iterator.
    $output = $this->createMock(ChatOutput::class);
    $output->method('getNormalized')->willReturn($iterator);
    return $output;
  }

  /**
   * Creates a mock tool call with a given name and ID.
   */
  private function createMockToolCall(string $name, string $id): object {
    $toolCall = $this->createMock(
      \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface::class
    );
    $toolCall->method('getName')->willReturn($name);
    $toolCall->method('getToolId')->willReturn($id);
    $toolCall->method('getArguments')->willReturn([]);
    return $toolCall;
  }

}
