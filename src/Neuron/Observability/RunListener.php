<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Observability;

use Drupal\ai_neuron\Observability\EventSummary;
use Drupal\ai_neuron\Observability\NeuronListenerInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Chunks\AgentEventChunk;
use NeuronAI\Agent\Observability\Validated;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Observability\WorkflowError;

/**
 * Streams the events of one run to the editor's browser.
 *
 * The site log is ai_neuron's job, and its own listener does it at the level a
 * site configures. The conversation is the store's job. What is left is the
 * live view of a run, which only the request serving it can deliver. It is
 * subscribed per run rather than tagged as a site listener, because the queue
 * it writes to belongs to one chat turn.
 */
final class RunListener implements NeuronListenerInterface {

  /**
   * Class constructor.
   *
   * @param string $agentId
   *   The plugin whose run this is, named on every chunk.
   * @param \Drupal\oe_ai_assistant\Neuron\Observability\AgentEventQueue $events
   *   The queue the stream loop drains.
   */
  public function __construct(
    private readonly string $agentId,
    private readonly AgentEventQueue $events,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function onEvent(ObservabilityEvent $event): void {
    // @todo Temporary: the full payload (prompts, answers, tool results)
    //   streams to the browser console so the run can be inspected. Gate it
    //   behind a dev-only configuration before this leaves development.
    $rejected = $event instanceof Validated && $event->violations !== [];
    $this->events->push(new AgentEventChunk(
      $event->name(),
      $this->agentId,
      EventSummary::summarize($event),
      $event->toArray(),
      $rejected || $event instanceof WorkflowError ? 'error' : 'info',
    ));
  }

}
