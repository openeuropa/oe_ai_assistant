<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Streaming;

use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\Stream\ChunkEvent;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\Stream\StartEvent;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\ServerEvent;

/**
 * Emits UI Message Stream Protocol events from Symfony AI chunks.
 *
 * Attached to a StreamResult before iteration. Uses Symfony's
 * EventStreamResponse::sendEvent() for all SSE output -- no
 * manual echo/flush.
 */
class DataStreamListener extends AbstractStreamListener {

  /**
   * Whether a text segment is currently open.
   *
   * @var bool
   */
  private bool $textStarted = FALSE;

  /**
   * The current text segment's unique ID.
   *
   * @var string
   */
  private string $textId;

  /**
   * Accumulated text from all TextDelta and string chunks.
   *
   * @var string
   */
  private string $accumulatedText = '';

  /**
   * Constructs a DataStreamListener.
   *
   * @param \Symfony\Component\HttpFoundation\EventStreamResponse $response
   *   The SSE response for sending events.
   * @param string $messageId
   *   The message ID for the "start" event.
   * @param \Closure|null $fieldDeltaObserver
   *   Optional callback for progressive field streaming.
   *   Signature: fn(string $toolName, string $partialJson).
   */
  public function __construct(
    private readonly EventStreamResponse $response,
    private readonly string $messageId,
    private readonly ?\Closure $fieldDeltaObserver = NULL,
  ) {
    $this->textId = bin2hex(random_bytes(8));
  }

  /**
   * {@inheritdoc}
   */
  public function onStart(StartEvent $event): void {
    $this->emitSse('start', ['messageId' => $this->messageId]);
    $this->emitSse('start-step');
  }

  /**
   * {@inheritdoc}
   */
  public function onChunk(ChunkEvent $event): void {
    $chunk = $event->getChunk();

    // String chunks from AgentProcessor re-invocation.
    if (is_string($chunk)) {
      $this->startTextIfNeeded();
      $this->emitSse('text-delta', ['textDelta' => $chunk]);
      $this->accumulatedText .= $chunk;
      return;
    }

    if ($chunk instanceof TextDelta) {
      $this->startTextIfNeeded();
      $this->emitSse('text-delta', [
        'textDelta' => $chunk->getText(),
      ]);
      $this->accumulatedText .= $chunk->getText();
    }
    elseif ($chunk instanceof ToolCallStart) {
      $this->finishTextIfStarted();
    }
    elseif ($chunk instanceof ToolInputDelta) {
      $this->emitSse('tool-call-delta', [
        'argsText' => $chunk->getPartialJson(),
      ]);
      if ($this->fieldDeltaObserver !== NULL) {
        ($this->fieldDeltaObserver)(
          $chunk->getName(),
          $chunk->getPartialJson(),
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onComplete(CompleteEvent $event): void {
    $this->finishTextIfStarted();
    $this->emitSse('finish-step', [
      'finishReason' => 'stop',
      'usage' => ['inputTokens' => 0, 'outputTokens' => 0],
      'isContinued' => FALSE,
    ]);
    $this->emitSse('finish', [
      'finishReason' => 'stop',
      'usage' => ['inputTokens' => 0, 'outputTokens' => 0],
    ]);
    $this->response->sendEvent(new ServerEvent('[DONE]'));
  }

  /**
   * Returns the accumulated assistant text.
   *
   * @return string
   *   The accumulated text.
   */
  public function getAccumulatedText(): string {
    return $this->accumulatedText;
  }

  /**
   * Emits a UI Message Stream Protocol SSE event.
   *
   * Public so that plugin code (e.g. ToolCallSucceeded listeners)
   * can emit events through the same response.
   *
   * @param string $type
   *   The event type discriminator.
   * @param array<string, mixed> $data
   *   Additional payload fields.
   */
  public function emitSse(string $type, array $data = []): void {
    $data['type'] = $type;
    $json = json_encode(
      $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );
    $this->response->sendEvent(new ServerEvent($json));
  }

  /**
   * Opens a text segment if one is not already open.
   */
  private function startTextIfNeeded(): void {
    if (!$this->textStarted) {
      $this->emitSse('text-start', ['id' => $this->textId]);
      $this->textStarted = TRUE;
    }
  }

  /**
   * Closes the current text segment if one is open.
   */
  private function finishTextIfStarted(): void {
    if ($this->textStarted) {
      $this->emitSse('text-end');
      $this->textStarted = FALSE;
      $this->textId = bin2hex(random_bytes(8));
    }
  }

}
