<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters;

use Drupal\oe_ai_assistant\Neuron\Tools\ToolResult;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

/**
 * Renders Neuron chunks in the UI message stream dialect of assistant-ui.
 *
 * The installed decoder speaks its own vocabulary: text deltas carry the
 * text under textDelta, a tool call opens, streams and closes in three
 * parts, and a result arrives as tool-result. Agent events travel as
 * transient data parts, which the app receives as they arrive without
 * adding them to the message.
 */
final class UiMessageStreamAdapter extends VercelAIAdapter {

  /**
   * {@inheritdoc}
   *
   * The app opens its message on the start event, so it is emitted before
   * the first chunk rather than lazily with it.
   */
  public function start(): iterable {
    yield from $this->startMessage();
  }

  /**
   * {@inheritdoc}
   */
  public function end(): iterable {
    yield new ProtocolEvent('finish', ['finishReason' => 'stop']);
  }

  /**
   * {@inheritdoc}
   */
  protected function handleText(TextChunk $chunk): iterable {
    if ($chunk->content !== '') {
      yield new ProtocolEvent('text-delta', ['textDelta' => $chunk->content]);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function handleToolCall(ToolCallChunk $chunk): iterable {
    yield from $this->toolCall($chunk->tool);
  }

  /**
   * {@inheritdoc}
   */
  protected function handleToolResult(ToolResultChunk $chunk): iterable {
    yield from $this->toolCall($chunk->tool);
    yield from $this->toolResult($chunk->tool);
  }

  /**
   * Streams the three parts that open a tool call with complete arguments.
   */
  private function toolCall(ToolCall $call): iterable {
    $id = $this->resolveToolCallId($call);
    if (isset($this->toolInputStarted[$id])) {
      return;
    }
    $this->toolInputStarted[$id] = TRUE;

    yield new ProtocolEvent('tool-call-start', ['toolCallId' => $id, 'toolName' => $call->getName()]);
    // An empty argument list must decode as an object on the client.
    yield new ProtocolEvent('tool-call-delta', [
      'toolCallId' => $id,
      'argsText' => json_encode($call->getInputs() ?: new \stdClass()),
    ]);
    yield new ProtocolEvent('tool-call-end', ['toolCallId' => $id]);
  }

  /**
   * Streams the result of a tool call.
   */
  private function toolResult(ToolCall $call): iterable {
    $id = $this->resolveToolCallId($call);
    if (isset($this->knownOutputs[$id])) {
      return;
    }
    $this->knownOutputs[$id] = TRUE;
    $result = ToolResult::decode((string) $call->getResult());

    yield new ProtocolEvent('tool-result', [
      'toolCallId' => $id,
      'result' => $result === [] ? new \stdClass() : $result,
    ]);
  }

}
