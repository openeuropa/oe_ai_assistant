<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\Response\AiStreamedResponse;
use Drupal\ai\Service\PromptCodeBlockExtractor\PromptCodeBlockExtractorInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes SSE events in the Vercel AI SDK UI Message Stream v1 protocol.
 *
 * This class encapsulates the SSE event format used by the Vercel AI SDK
 * (https://sdk.vercel.ai/docs/ai-sdk-ui/stream-protocol), providing a
 * clean API for plugins to emit streaming events without coupling to the
 * wire format. Plugins call high-level methods (start, textDelta, finish)
 * and this class handles JSON encoding, SSE framing, and flushing.
 *
 * Usage:
 * @code
 * $stream = \Drupal::service('oe_ai_assistant.ui_message_stream');
 * return $stream->respond(function ($stream) {
 *   $stream->start();
 *   $stream->startStep('main_fields');
 *   $stream->textDelta('Hello world');
 *   $stream->finishStep('main_fields');
 *   $stream->finish();
 * });
 * @endcode
 *
 * @see https://sdk.vercel.ai/docs/ai-sdk-ui/stream-protocol
 */
class UiMessageStream implements UiMessageStreamInterface {

  /**
   * The code block extractor for parsing LLM JSON responses.
   *
   * @var \Drupal\ai\Service\PromptCodeBlockExtractor\PromptCodeBlockExtractorInterface
   */
  protected PromptCodeBlockExtractorInterface $codeBlockExtractor;

  /**
   * Constructs a UiMessageStream.
   *
   * @param \Drupal\ai\Service\PromptCodeBlockExtractor\PromptCodeBlockExtractorInterface $codeBlockExtractor
   *   The code block extractor service.
   */
  public function __construct(PromptCodeBlockExtractorInterface $codeBlockExtractor) {
    $this->codeBlockExtractor = $codeBlockExtractor;
  }

  /**
   * {@inheritdoc}
   */
  public function respond(callable $callback): Response {
    $response = new AiStreamedResponse(NULL, 200, [
      'Content-Type' => 'text/event-stream',
      'x-vercel-ai-ui-message-stream' => 'v1',
    ]);

    $stream = $this;
    $response->setCallback(function () use ($callback, $stream): void {
      set_time_limit(0);
      $callback($stream);
    });

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function start(?string $messageId = NULL): void {
    $messageId = $messageId ?? bin2hex(random_bytes(16));
    $this->emit('start', ['messageId' => $messageId]);
  }

  /**
   * {@inheritdoc}
   */
  public function startStep(string $stepId = ''): void {
    $data = $stepId !== '' ? ['stepId' => $stepId] : [];
    $this->emit('start-step', $data);
  }

  /**
   * {@inheritdoc}
   */
  public function textDelta(string $text): void {
    if ($text === '') {
      return;
    }
    $this->emit('text-delta', ['textDelta' => $text]);
  }

  /**
   * {@inheritdoc}
   */
  public function finishStep(string $stepId = ''): void {
    $data = $stepId !== '' ? ['stepId' => $stepId] : [];
    $this->emit('finish-step', $data);
  }

  /**
   * {@inheritdoc}
   */
  public function customEvent(string $type, array $data): void {
    $this->emit($type, ['data' => $data]);
  }

  /**
   * {@inheritdoc}
   */
  public function error(string $errorText, string $step = ''): void {
    $data = ['errorText' => $errorText];
    if ($step !== '') {
      $data['step'] = $step;
    }
    $this->emit('error', $data);
  }

  /**
   * {@inheritdoc}
   */
  public function finish(string $finishReason = 'stop'): void {
    $this->emit('finish', [
      'finishReason' => $finishReason,
    ]);
    echo "data: [DONE]\n\n";
    flush();
  }

  /**
   * {@inheritdoc}
   */
  public function streamChatOutput(ChatOutput $chatOutput, string $stepId = ''): array {
    $this->startStep($stepId);

    $normalized = $chatOutput->getNormalized();
    if ($normalized instanceof StreamedChatMessageIteratorInterface) {
      // Reduce the buffer so text-delta events arrive in small
      // chunks rather than 100-char batches.
      $normalized->setMaxBufferSize(5);
      foreach ($normalized as $chunk) {
        $this->textDelta($chunk->getText() ?? '');
      }
      $toolCalls = $normalized->getTools();
    }
    else {
      $this->textDelta($normalized->getText() ?? '');
      $toolCalls = $normalized->getTools() ?? [];
    }

    $this->finishStep($stepId);

    return $toolCalls;
  }

  /**
   * {@inheritdoc}
   */
  public function extractJson(string $text): ?array {
    $text = trim($text);
    if ($text === '') {
      return NULL;
    }

    // Try raw JSON first.
    $parsed = json_decode($text, TRUE);
    if (is_array($parsed)) {
      return $parsed;
    }

    // Use PromptCodeBlockExtractor to strip markdown fencing.
    $extracted = $this->codeBlockExtractor->extract($text, 'json');
    if (is_string($extracted) && $extracted !== $text) {
      $parsed = json_decode(trim($extracted), TRUE);
      if (is_array($parsed)) {
        return $parsed;
      }
    }

    return NULL;
  }

  /**
   * Emits a single SSE event.
   *
   * @param string $type
   *   The event type.
   * @param array $data
   *   The event data payload.
   */
  protected function emit(string $type, array $data): void {
    $data['type'] = $type;
    $json = json_encode(
      $data,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
    echo "data: $json\n\n";
    flush();
  }

}
