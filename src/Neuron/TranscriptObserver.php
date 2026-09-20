<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use NeuronAI\Observability\Events\AgentError;
use Psr\Log\LoggerInterface;

/**
 * Frames, annotates and logs one agent run.
 *
 * The turns themselves are persisted by the conversation chat history. This
 * observer adds what the history cannot see: the system prompt of a drafter,
 * the step boundaries on the stream, and a failed run.
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
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param string $agentId
   *   The agent id stored on every recorded row.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The turn the recorded rows nest under, or NULL for top-level turns.
   * @param string|null $systemPrompt
   *   When given, recorded as a system row before the first inference.
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface|null $stream
   *   When given, every inference is framed as a step on the stream.
   */
  public function __construct(
    LoggerInterface $logger,
    private readonly MessageRecorderInterface $recorder,
    private readonly EntityInterface $host,
    private readonly string $agentId,
    private readonly ?AiConversationMessageInterface $parent = NULL,
    private readonly ?string $systemPrompt = NULL,
    private readonly ?UiMessageStreamInterface $stream = NULL,
  ) {
    parent::__construct($logger);
  }

  /**
   * {@inheritdoc}
   */
  public function onEvent(string $event, object $source, mixed $data = NULL, ?string $branchId = NULL): void {
    parent::onEvent($event, $source, $data, $branchId);

    match ($event) {
      'inference-start' => $this->onInferenceStart(),
      'inference-stop' => $this->stream?->finishStep($this->agentId),
      'error' => $data instanceof AgentError ? $this->onError($data->exception) : NULL,
      default => NULL,
    };
  }

  /**
   * Records the system prompt once and opens the step before a model call.
   */
  private function onInferenceStart(): void {
    if ($this->systemPrompt !== NULL && !$this->systemRecorded) {
      $this->recorder->recordSystem($this->host, $this->systemPrompt, $this->agentId, $this->parent);
      $this->systemRecorded = TRUE;
    }
    $this->stream?->startStep($this->agentId);
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
      $this->recorder->recordError($this->host, $exception->getMessage(), $this->agentId, $this->parent);
    }
  }

}
