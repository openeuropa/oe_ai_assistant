<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters;

use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

/**
 * Closes the input of a tool call, so a client can render it as it happens.
 *
 * The protocol opens a call's input with tool-input-start, streams it with
 * tool-input-delta and closes it with tool-input-available. Neuron sends the
 * closing event only for a call it suspends on, so a call it runs itself is
 * left open. A client holds an open call back, because it cannot know the
 * arguments are complete, and the call and its result then arrive together
 * when the stream ends rather than as they happen.
 *
 * @todo Remove once Neuron closes the input of a locally executed call.
 */
final class ClosedToolInputAdapter extends VercelAIAdapter {

  /**
   * The calls whose input has been closed, so it is closed once.
   *
   * @var array<string, bool>
   */
  private array $inputsClosed = [];

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
