<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Result of an LLM streaming loop run.
 *
 * An immutable value object returned by LlmStreamingLoop::run(). It carries
 * two pieces of information the caller needs after the loop completes:
 *
 * 1. The final assistant text: the last streamed text response from the LLM
 *    after all tool-call iterations have finished. This is the text that was
 *    already sent to the client via SSE; it is included here so the caller
 *    can append it to the persisted conversation history.
 *
 * 2. The full message array: the running conversation history including all
 *    assistant messages, tool call messages, and tool result messages
 *    appended during the loop. The caller is responsible for persisting
 *    this array (e.g. via ConversationHistory::save()) so that future
 *    requests in the same thread have full context.
 */
class LlmLoopResult {

  /**
   * Constructs a new LlmLoopResult.
   *
   * @param string $assistantText
   *   The final assistant text response streamed to the client. Will be
   *   an empty string if the loop exited without a text response (e.g.
   *   the iteration limit was reached after only tool calls, or the LLM
   *   produced only tool calls without any accompanying text). Callers
   *   should handle the empty case gracefully.
   * @param array $messages
   *   The full conversation message array as it stood at loop exit. This
   *   includes all messages from the original $config->conversationHistory
   *   plus any assistant/tool-call/tool-result messages appended during
   *   the loop iterations. Each element is an associative array with at
   *   least 'role' and 'content' keys, and optionally 'tool_call_id' and
   *   'tool_calls_raw' for tool-related messages.
   */
  public function __construct(
    public readonly string $assistantText,
    public readonly array $messages,
  ) {}

}
