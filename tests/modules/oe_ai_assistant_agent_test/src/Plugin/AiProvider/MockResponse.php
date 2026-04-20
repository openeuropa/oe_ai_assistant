<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider;

/**
 * Value object representing a queued mock LLM response.
 *
 * Text and toolCalls are mutually exclusive, matching real LLM behavior
 * where the model either responds with text OR with tool calls.
 */
class MockResponse {

  /**
   * Constructs a MockResponse.
   *
   * @param string $text
   *   The full response text. Split into word tokens for streaming.
   * @param array|null $toolCalls
   *   Optional tool call array (complete per chunk, not incremental).
   * @param int $delay
   *   Microseconds between token yields (default 50ms).
   * @param \Throwable|null $error
   *   Optional exception to throw during streaming.
   * @param array|null $tokenUsage
   *   Optional token usage: ['input' => N, 'output' => N].
   */
  public function __construct(
    public readonly string $text = '',
    public readonly ?array $toolCalls = NULL,
    public readonly int $delay = 50000,
    public readonly ?\Throwable $error = NULL,
    public readonly ?array $tokenUsage = NULL,
  ) {}

}
