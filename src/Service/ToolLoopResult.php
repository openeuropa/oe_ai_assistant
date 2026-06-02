<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Value object returned by ToolExecutionLoop::run().
 *
 * Describes how the tool loop ended so the caller can take the
 * appropriate action (persist history, trigger orchestration, etc.).
 *
 * Why a value object instead of an array:
 * - The result has a fixed structure (finishReason, terminalTool,
 *   responseText) that benefits from type safety.
 * - The caller can use match/switch on finishReason without
 *   guessing what keys exist.
 * - Easy to test: construct with known values, assert properties.
 */
class ToolLoopResult {

  /**
   * Constructs a ToolLoopResult.
   *
   * @param string $finishReason
   *   How the loop ended: 'stop' (text response), 'tool_calls'
   *   (a terminal tool was called), or 'max_iterations' (safety
   *   limit reached).
   * @param string $terminalToolName
   *   The name of the terminal tool that was called (e.g.
   *   'draft_content'), or empty string if the loop ended with
   *   text or max iterations.
   * @param string $responseText
   *   The assistant's text response (for 'stop' finish reason),
   *   or empty string if the LLM called a tool instead.
   */
  public function __construct(
    public readonly string $finishReason,
    public readonly string $terminalToolName = '',
    public readonly string $responseText = '',
  ) {}

  /**
   * Whether the loop ended because a terminal tool was called.
   */
  public function hasTerminalTool(): bool {
    return $this->terminalToolName !== '';
  }

  /**
   * Whether the loop ended with a text response.
   */
  public function hasText(): bool {
    return $this->responseText !== '';
  }

}
