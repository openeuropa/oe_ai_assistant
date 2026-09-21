<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters;

use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Chunks\AgentEventChunk;
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolInterface;

/**
 * Renders Neuron chunks in the UI message stream dialect of assistant-ui.
 *
 * The app decodes text deltas, tool call start, delta and end parts and
 * tool results. Agent events travel as transient data parts, which the app
 * receives as they arrive without adding them to the message.
 */
final class UiMessageStreamAdapter extends VercelAIAdapter {

  /**
   * {@inheritdoc}
   */
  public function start(): iterable {
    $this->started = TRUE;
    yield $this->sse(['type' => 'start', 'messageId' => $this->generateId('msg')]);
  }

  /**
   * {@inheritdoc}
   */
  public function transform(object $chunk): iterable {
    if ($chunk instanceof AgentEventChunk) {
      yield from $this->handleAgentEvent($chunk);
      return;
    }
    yield from parent::transform($chunk);
  }

  /**
   * Streams an error the editor can read, in place of the failed turn.
   */
  public function error(string $text): iterable {
    yield $this->sse(['type' => 'error', 'errorText' => $text]);
  }

  /**
   * {@inheritdoc}
   */
  public function end(): iterable {
    yield $this->sse(['type' => 'finish', 'finishReason' => 'stop']);
    yield "data: [DONE]\n\n";
  }

  /**
   * {@inheritdoc}
   */
  protected function handleText(TextChunk $chunk): iterable {
    if ($chunk->content !== '') {
      yield $this->sse(['type' => 'text-delta', 'textDelta' => $chunk->content]);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function handleToolCall(ToolCallChunk $chunk): iterable {
    yield from $this->toolCall($this->callId($chunk->tool), $chunk->tool->getName(), $chunk->tool->getInputs());
  }

  /**
   * {@inheritdoc}
   */
  protected function handleToolResult(ToolResultChunk $chunk): iterable {
    yield from $this->toolResult($this->callId($chunk->tool), $this->decode($chunk->tool->getResult()));
  }

  /**
   * Streams an agent event as a transient data part.
   */
  private function handleAgentEvent(AgentEventChunk $chunk): iterable {
    yield $this->sse([
      'type' => 'data-agent-event',
      'data' => $chunk->toArray(),
      'transient' => TRUE,
    ]);
  }

  /**
   * Streams the three parts that open a tool call with complete arguments.
   */
  private function toolCall(string $id, string $name, array $arguments): iterable {
    yield $this->sse(['type' => 'tool-call-start', 'toolCallId' => $id, 'toolName' => $name]);
    // An empty argument list must decode as an object on the client.
    yield $this->sse([
      'type' => 'tool-call-delta',
      'toolCallId' => $id,
      'argsText' => json_encode($arguments ?: new \stdClass()),
    ]);
    yield $this->sse(['type' => 'tool-call-end', 'toolCallId' => $id]);
  }

  /**
   * Streams the result of a tool call.
   */
  private function toolResult(string $id, mixed $result): iterable {
    yield $this->sse([
      'type' => 'tool-result',
      'toolCallId' => $id,
      'result' => $result === [] ? new \stdClass() : $result,
    ]);
  }

  /**
   * Returns the id the provider assigned to a call, or one derived from it.
   */
  private function callId(ToolInterface $tool): string {
    return $tool->getCallId() ?: 'call_' . spl_object_id($tool);
  }

  /**
   * Decodes a tool result for the client, keeping plain text as text.
   */
  private function decode(string $result): mixed {
    $decoded = json_decode($result, TRUE);
    return is_array($decoded) ? $decoded : ['text' => $result];
  }

}
