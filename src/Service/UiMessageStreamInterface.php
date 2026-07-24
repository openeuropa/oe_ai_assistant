<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\OperationType\Chat\ChatOutput;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface for the UI Message Stream service.
 *
 * Provides an API for emitting SSE events in the Vercel AI SDK
 * UI Message Stream v1 protocol.
 *
 * @see https://sdk.vercel.ai/docs/ai-sdk-ui/stream-protocol
 */
interface UiMessageStreamInterface {

  /**
   * Creates an AiStreamedResponse that executes the given callback.
   *
   * @param callable $callback
   *   A callback that receives a UiMessageStreamInterface instance.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The streaming response.
   */
  public function respond(callable $callback): Response;

  /**
   * Emits a start event with a unique message ID.
   *
   * @param string|null $messageId
   *   Optional message ID. Generated if not provided.
   */
  public function start(?string $messageId = NULL): void;

  /**
   * Emits a start-step event.
   *
   * @param string $stepId
   *   Optional step identifier for the UI to track.
   */
  public function startStep(string $stepId = ''): void;

  /**
   * Emits a text-delta event with a chunk of text.
   *
   * @param string $text
   *   The text chunk to emit.
   */
  public function textDelta(string $text): void;

  /**
   * Emits a finish-step event.
   *
   * @param string $stepId
   *   Optional step identifier for the UI to mark as completed.
   */
  public function finishStep(string $stepId = ''): void;

  /**
   * Emits a custom event with arbitrary data.
   *
   * @param string $type
   *   The event type name.
   * @param array $data
   *   The event payload.
   */
  public function customEvent(string $type, array $data): void;

  /**
   * Emits a completed tool call with its result.
   *
   * Renders as a tool call part in the chat (start, args, end, result), so a
   * caller-handled tool (e.g. draft_content) leaves a visible trace inline.
   *
   * @param string $toolName
   *   The tool name.
   * @param array $args
   *   The tool arguments; serialized to JSON.
   * @param array $result
   *   The tool result payload.
   */
  public function toolCall(string $toolName, array $args, array $result): void;

  /**
   * Emits an error event.
   *
   * @param string $errorText
   *   The error message.
   * @param string $step
   *   Optional step identifier where the error occurred.
   */
  public function error(string $errorText, string $step = ''): void;

  /**
   * Emits a finish event and the [DONE] terminator.
   *
   * @param string $finishReason
   *   The reason for finishing (e.g. 'stop', 'tool_calls').
   */
  public function finish(string $finishReason = 'stop'): void;

  /**
   * Streams a ChatOutput, emitting text-delta events for each chunk.
   *
   * Handles both streamed and non-streamed responses. Emits
   * start-step/finish-step around the streaming. Returns any tool
   * calls found in the response.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $chatOutput
   *   The LLM response to stream.
   * @param string $stepId
   *   Optional step identifier.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface[]
   *   Tool calls found in the response (empty array if none).
   */
  public function streamChatOutput(ChatOutput $chatOutput, string $stepId = ''): array;

  /**
   * Extracts a JSON object from LLM text output.
   *
   * Handles both raw JSON and markdown-fenced JSON.
   *
   * @param string $text
   *   The raw LLM output text.
   *
   * @return array|null
   *   Decoded JSON as array, or NULL on parse failure.
   */
  public function extractJson(string $text): ?array;

}
