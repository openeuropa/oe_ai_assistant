<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Observability;

use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Chunks\AgentEventChunk;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\Validated;
use Psr\Log\LoggerInterface;

/**
 * Records and queues every event of one agent run.
 *
 * The turns themselves are persisted by the conversation chat history. This
 * observer adds what the history cannot see: an event row and a stream
 * chunk per Neuron event, each tool result as soon as the tool finishes,
 * the system prompt of a drafter, a rejected answer, and a failed run.
 */
final class TranscriptObserver extends DrupalLogObserver {

  /**
   * Whether the system prompt row has been recorded for this run.
   */
  private bool $systemRecorded = FALSE;

  /**
   * TranscriptObserver constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel every event is written to.
   * @param \Drupal\oe_ai_assistant\Service\MessageRecorderInterface $recorder
   *   The message recorder.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param string $agentId
   *   The agent id stored on every recorded row.
   * @param \Drupal\oe_ai_assistant\Neuron\Observability\AgentEventQueue $events
   *   The queue the stream loop drains.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The turn the recorded rows nest under, or NULL for top-level turns.
   * @param string|null $systemPrompt
   *   When given, recorded as a system row before the first inference.
   * @param \Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory|null $history
   *   When given, receives each tool result as soon as the tool finishes.
   */
  public function __construct(
    LoggerInterface $logger,
    private readonly MessageRecorderInterface $recorder,
    private readonly AiEditorialSessionInterface $session,
    private readonly string $agentId,
    private readonly AgentEventQueue $events,
    private readonly ?AiConversationMessageInterface $parent = NULL,
    private readonly ?string $systemPrompt = NULL,
    private readonly ?ConversationChatHistory $history = NULL,
  ) {
    parent::__construct($logger);
  }

  /**
   * {@inheritdoc}
   */
  public function onEvent(string $event, object $source, mixed $data = NULL, ?string $branchId = NULL): void {
    parent::onEvent($event, $source, $data, $branchId);

    if ($event === 'inference-start' && $this->systemPrompt !== NULL && !$this->systemRecorded) {
      $this->recorder->recordSystem($this->session, $this->systemPrompt, $this->agentId, $this->parent);
      $this->systemRecorded = TRUE;
    }
    if ($event === 'error' && $data instanceof AgentError) {
      $this->onError($data->exception);
    }
    if ($data instanceof ToolCalled) {
      $this->history?->attachToolResult($data->tool);
    }

    // A rejected answer is a failure the editor and the site log should
    // see, even though the run recovers from it by asking again.
    $rejected = $data instanceof Validated && $data->violations !== [];
    if ($rejected) {
      $this->logger->error('Agent @agent answered outside the @schema schema: @violations', [
        '@agent' => $this->agentId,
        '@schema' => $data->class,
        '@violations' => implode('; ', $data->violations),
      ]);
    }

    $summary = AgentEventSummary::describe($event, $data);
    $this->recorder->recordEvent($this->session, $summary, [
      'type' => 'agent',
      'event' => $event,
      'agent' => $this->agentId,
    ]);
    // @todo Temporary: the full payload (prompts, answers, tool results)
    //   streams to the browser console so the run can be inspected. Gate it
    //   behind a dev-only configuration before this leaves development.
    $payload = json_decode((string) json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE), TRUE);
    $this->events->push(new AgentEventChunk(
      $event,
      $this->agentId,
      $summary,
      $payload,
      $rejected || $event === 'error' ? 'error' : 'info',
    ));
  }

  /**
   * Logs a failed run and records it under the parent turn, if any.
   */
  private function onError(\Throwable $exception): void {
    $this->logger->error('Agent @agent failed: @error @trace', [
      '@agent' => $this->agentId,
      '@error' => $exception->getMessage(),
      '@trace' => $exception->getTraceAsString(),
    ]);
    if ($this->parent !== NULL) {
      $this->recorder->recordError($this->session, $exception->getMessage(), $this->agentId, $this->parent);
    }
  }

}
