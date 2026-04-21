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
 * return UiMessageStream::respond(function (UiMessageStream $stream) {
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
   * Creates an AiStreamedResponse that executes the given callback.
   *
   * The callback receives a UiMessageStream instance and should call
   * its methods to emit SSE events. The response handles headers,
   * output buffering, and connection management.
   *
   * @param callable $callback
   *   A callback that receives this UiMessageStream instance.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The streaming response.
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
   * Emits a start event with a unique message ID.
   *
   * @param string|null $messageId
   *   Optional message ID. Generated if not provided.
   */
  public function start(?string $messageId = NULL): void {
    $messageId = $messageId ?? bin2hex(random_bytes(16));
    $this->emit('start', ['messageId' => $messageId]);
  }

  /**
   * Emits a start-step event.
   *
   * @param string $stepId
   *   Optional step identifier for the UI to track.
   */
  public function startStep(string $stepId = ''): void {
    $data = $stepId !== '' ? ['stepId' => $stepId] : [];
    $this->emit('start-step', $data);
  }

  /**
   * Emits a text-delta event with a chunk of text.
   *
   * @param string $text
   *   The text chunk to emit.
   */
  public function textDelta(string $text): void {
    $this->emit('text-delta', ['textDelta' => $text]);
  }

  /**
   * Emits a finish-step event.
   *
   * @param string $stepId
   *   Optional step identifier for the UI to mark as completed.
   */
  public function finishStep(string $stepId = ''): void {
    $data = $stepId !== '' ? ['stepId' => $stepId] : [];
    $this->emit('finish-step', $data);
  }

  /**
   * Emits a custom event with arbitrary data.
   *
   * Use this for application-specific events like 'data-plan' or
   * 'data-drafted-fields' that are not part of the base protocol.
   *
   * @param string $type
   *   The event type name.
   * @param array $data
   *   The event payload.
   */
  public function customEvent(string $type, array $data): void {
    $this->emit($type, $data);
  }

  /**
   * Emits an error event.
   *
   * @param string $errorText
   *   The error message.
   * @param string $step
   *   Optional step identifier where the error occurred.
   */
  public function error(string $errorText, string $step = ''): void {
    $data = ['errorText' => $errorText];
    if ($step !== '') {
      $data['step'] = $step;
    }
    $this->emit('error', $data);
  }

  /**
   * Emits a finish event and the [DONE] terminator.
   *
   * @param string $finishReason
   *   The reason for finishing (e.g. 'stop', 'tool_calls').
   */
  public function finish(string $finishReason = 'stop'): void {
    $this->emit('finish', [
      'finishReason' => $finishReason,
    ]);
    echo "data: [DONE]\n\n";
    flush();
  }

  /**
   * Streams a ChatOutput, emitting text-delta events for each chunk.
   *
   * Handles both streamed and non-streamed responses. After streaming
   * completes, returns any tool calls found in the response.
   *
   * This method emits start-step/finish-step around the streaming.
   * If you need more control over step boundaries, iterate the
   * ChatOutput yourself and call textDelta() directly.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $chatOutput
   *   The LLM response to stream.
   * @param string $stepId
   *   Optional step identifier.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface[]
   *   Tool calls found in the response (empty array if none).
   */
  public function streamChatOutput(ChatOutput $chatOutput, string $stepId = ''): array {
    $this->startStep($stepId);

    $normalized = $chatOutput->getNormalized();
    if ($normalized instanceof StreamedChatMessageIteratorInterface) {
      foreach ($normalized as $chunk) {
        $text = $chunk->getText();
        if ($text !== '' && $text !== NULL) {
          $this->textDelta($text);
        }
      }
      $toolCalls = $normalized->getTools();
    }
    else {
      $text = $normalized->getText();
      if ($text !== '' && $text !== NULL) {
        $this->textDelta($text);
      }
      $toolCalls = $normalized->getTools() ?? [];
    }

    $this->finishStep($stepId);

    return $toolCalls;
  }

  /**
   * Extracts a JSON object from LLM text output.
   *
   * Handles both raw JSON and markdown-fenced JSON (```json...```)
   * that real providers like Mistral often return despite system
   * prompt instructions or structured output settings.
   *
   * @param string $text
   *   The raw LLM output text.
   *
   * @return array|null
   *   Decoded JSON as array, or NULL on parse failure.
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
    $json = json_encode(
      ['type' => $type, 'data' => $data],
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
    echo "data: $json\n\n";
    flush();
  }

}
