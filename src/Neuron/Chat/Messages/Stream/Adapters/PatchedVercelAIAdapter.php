<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters;

use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

/**
 * Neuron's Vercel adapter, with two protocol gaps closed.
 *
 * The protocol opens a call's input with tool-input-start, streams it with
 * tool-input-delta and closes it with tool-input-available. Neuron sends the
 * closing event only for a call it suspends on, so a call it runs itself is
 * left open. A client holds an open call back, because it cannot know the
 * arguments are complete, and the call and its result then arrive together
 * when the stream ends rather than as they happen.
 *
 * Neuron also answers a rejected call with tool-output-denied, which carries
 * no result and which the client's stream reader has no case for. Such a
 * call reaches the thread with no result on it, so it reads as one still
 * waiting for its answer.
 *
 * @todo Remove once Neuron closes the input of a locally executed call and
 *   answers a rejected one in a frame the reader understands.
 */
final class PatchedVercelAIAdapter extends VercelAIAdapter {

  /**
   * The calls whose input has been closed, so it is closed once.
   *
   * @var array<string, bool>
   */
  private array $inputsClosed = [];

  /**
   * {@inheritdoc}
   */
  protected function handleToolResult(ToolResultChunk $chunk): iterable {
    foreach (parent::handleToolResult($chunk) as $frame) {
      // The denial names the call and stops there, so the text the agent
      // answered it with is restored here: it is what the editor reads on
      // the card, and what tells the card the call is settled.
      if ($frame instanceof ProtocolEvent && $frame->type === 'tool-output-denied') {
        yield new ProtocolEvent('tool-output-available', [
          'toolCallId' => $frame->data['toolCallId'],
          'output' => (string) $chunk->tool->getResult(),
        ]);

        continue;
      }

      yield $frame;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function previewTool(ToolCall $call): iterable {
    yield from parent::previewTool($call);

    $id = $this->resolveToolCallId($call);
    if (isset($this->inputsClosed[$id])) {
      return;
    }
    $this->inputsClosed[$id] = TRUE;

    yield new ProtocolEvent('tool-input-available', [
      'toolCallId' => $id,
      'toolName' => $call->getName(),
      'input' => (object) $call->getInputs(),
    ]);
  }

}
