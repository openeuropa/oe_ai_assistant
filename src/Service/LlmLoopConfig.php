<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\OperationType\Chat\Tools\ToolsInput;

/**
 * Configuration for a single LLM streaming loop run.
 *
 * Captures all inputs needed by LlmStreamingLoop::run(). The
 * toolExecutor callback is the extension point: each plugin
 * provides a closure that handles its own tool calls.
 */
class LlmLoopConfig {

  /**
   * Constructs a new LlmLoopConfig.
   *
   * @param string $systemPrompt
   *   The system prompt for the LLM.
   * @param array $conversationHistory
   *   Array of message arrays with role/content keys.
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsInput $tools
   *   The tool definitions for the LLM.
   * @param string $providerId
   *   The AI provider plugin ID.
   * @param string $modelId
   *   The model identifier.
   * @param string $messageId
   *   The initial message ID for SSE events.
   * @param \Closure $toolExecutor
   *   Callback: fn(array $toolCalls) => array of tool result
   *   messages. The loop calls startToolCall() before invoking
   *   this and finishToolCall() after it returns.
   * @param int $maxIterations
   *   Maximum tool-call loop iterations.
   */
  public function __construct(
    public readonly string $systemPrompt,
    public readonly array $conversationHistory,
    public readonly ToolsInput $tools,
    public readonly string $providerId,
    public readonly string $modelId,
    public readonly string $messageId,
    public readonly \Closure $toolExecutor,
    public readonly int $maxIterations = 10,
  ) {}

}
