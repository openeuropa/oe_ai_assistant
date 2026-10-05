<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Observability;

use Drupal\ai_neuron\Observability\EventSummary;
use Drupal\ai_neuron\Observability\NeuronListenerInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationMessageStore;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Chunks\AgentEventChunk;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Agent\Observability\Validated;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Observability\WorkflowError;

/**
 * Carries the events of one run to the editor and to the transcript.
 *
 * The site log is ai_neuron's job, and its own listener does it with levels
 * and a threshold a site configures. This one adds what only the session
 * knows: a stream chunk per event for the editor, each tool result on the
 * call that asked for it, the turn a tool nests its rows under, a drafter's
 * system prompt, and a failed drafter as a row of the conversation. It is
 * subscribed per run rather than tagged as a site listener, because it holds
 * the session and the turn its rows belong to.
 */
final class RunListener implements NeuronListenerInterface {

  /**
   * Whether the system prompt row has been recorded for this run.
   */
  private bool $systemRecorded = FALSE;

  /**
   * The turn that asked for tools, until the first of them runs.
   */
  private ?ToolCallMessage $pendingTurn = NULL;

  public function __construct(
    private readonly MessageRecorderInterface $recorder,
    private readonly AiEditorialSessionInterface $session,
    private readonly string $agentId,
    private readonly AgentEventQueue $events,
    private readonly ConversationMessageStore $store,
    private readonly string $threadId,
    private readonly ?AiConversationMessageInterface $parent = NULL,
    private readonly ?string $systemPrompt = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function onEvent(ObservabilityEvent $event): void {
    if ($event instanceof InferenceStart && $this->systemPrompt !== NULL && !$this->systemRecorded) {
      $this->recorder->recordSystem($this->session, $this->systemPrompt, $this->agentId, $this->parent);
      $this->systemRecorded = TRUE;
    }

    // A turn that asks for tools reaches the history only after they have
    // run, which is too late for a tool that nests its rows under the turn
    // that called it. It is written when the first tool runs instead, which
    // is after the history has written the turn it replies to, so the rows
    // stay in the order they happened. A turn that only answers is left to
    // the history entirely.
    if ($event instanceof InferenceStop && ($answer = $event->response->message()) instanceof ToolCallMessage) {
      $this->pendingTurn = $answer;
    }
    if ($event instanceof ToolCalling && $this->pendingTurn !== NULL) {
      $this->store->recordAssistant($this->threadId, $this->pendingTurn);
      $this->pendingTurn = NULL;
    }

    if ($event instanceof ToolCalled) {
      $this->store->attachToolResult($this->threadId, $event->tool);
    }
    // A failed drafter leaves a row under the turn that called it, so the
    // transcript shows where the run stopped.
    if ($event instanceof WorkflowError && $this->parent !== NULL) {
      $this->recorder->recordError($this->session, $event->exception->getMessage(), $this->agentId, $this->parent);
    }

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
