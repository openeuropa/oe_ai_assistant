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
   * The error message to throw, stored as string for serialization.
   *
   * @var string|null
   */
  public readonly ?string $errorMessage;

  /**
   * The error class to throw.
   *
   * @var string|null
   */
  public readonly ?string $errorClass;

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
   *   Optional exception to throw during streaming. Stored as class +
   *   message for serialization across processes.
   * @param array|null $tokenUsage
   *   Optional token usage: ['input' => N, 'output' => N].
   */
  public function __construct(
    public readonly string $text = '',
    public readonly ?array $toolCalls = NULL,
    public readonly int $delay = 50000,
    ?\Throwable $error = NULL,
    public readonly ?array $tokenUsage = NULL,
  ) {
    $this->errorMessage = $error?->getMessage();
    $this->errorClass = $error !== NULL ? get_class($error) : NULL;
  }

  /**
   * Returns whether this response should throw an error.
   *
   * @return bool
   *   TRUE if an error is configured.
   */
  public function hasError(): bool {
    return $this->errorClass !== NULL;
  }

  /**
   * Creates the exception to throw.
   *
   * @return \Throwable
   *   The reconstructed exception.
   */
  public function createError(): \Throwable {
    $class = $this->errorClass;
    return new $class($this->errorMessage ?? '');
  }

}
