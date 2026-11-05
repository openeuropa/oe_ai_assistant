<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters;

use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

/**
 * Neuron's Vercel adapter, with the input of a locally executed call closed.
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

  /**
   * {@inheritdoc}
   *
   * For a ToolResultsRequest (a call the run suspends on), the parent's own
   * interrupt() already closes the input with its own explicit event right
   * after calling previewTool(). Closing the call here first, before the
   * parent runs, keeps the override above from emitting a second one for
   * the same call.
   */
  public function interrupt(InterruptRequest $request): iterable {
    if ($request instanceof ToolResultsRequest) {
      foreach ($request->getToolCalls() as $call) {
        $this->inputsClosed[$this->resolveToolCallId($call)] = TRUE;
      }
    }

    yield from parent::interrupt($request);
  }

}
