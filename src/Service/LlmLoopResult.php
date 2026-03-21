<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Result of an LLM streaming loop run.
 *
 * Contains the final assistant text and the full conversation
 * message array (including tool call/result messages appended
 * during the loop).
 */
class LlmLoopResult {

  /**
   * Constructs a new LlmLoopResult.
   *
   * @param string $assistantText
   *   The final assistant text response (empty if tool-only).
   * @param array $messages
   *   The full message array including tool call/result messages.
   */
  public function __construct(
    public readonly string $assistantText,
    public readonly array $messages,
  ) {}

}
