<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider;

use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;

/**
 * Streams a MockResponse as word-by-word chat message chunks.
 *
 * Extends the base StreamedChatMessageIterator so the Drupal AI framework
 * handles buffering, security filtering, and ChatOutput reconstruction
 * identically to a real provider like Mistral or OpenAI.
 *
 * Tool calls are returned as proper ToolsFunctionOutput objects via
 * getTools(), bypassing the base class's assembleToolCalls() which
 * expects the OpenAI incremental delta format. The mock provider
 * delivers complete tool calls at once (like Mistral), so no
 * incremental assembly is needed.
 */
class MockChatMessageIterator extends StreamedChatMessageIterator {

  /**
   * The mock response to stream.
   *
   * @var \Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider\MockResponse
   */
  protected MockResponse $mockResponse;

  /**
   * Creates a MockChatMessageIterator for the given response.
   *
   * @param \Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider\MockResponse $mockResponse
   *   The mock response to stream.
   *
   * @return static
   *   A new iterator instance.
   */
  public static function fromMockResponse(MockResponse $mockResponse): static {
    // Create an empty IteratorAggregate to satisfy the interface.
    $emptyIterator = new \ArrayObject();
    $instance = new static($emptyIterator);
    $instance->mockResponse = $mockResponse;
    // Use a small buffer so tokens arrive in small chunks,
    // mimicking real LLM token-by-token streaming.
    $instance->setMaxBufferSize(5);
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Yields word-by-word text chunks or a single tool-call chunk,
   * with configurable delays to simulate real LLM streaming.
   */
  public function doIterate(): \Generator {
    // If error, throw immediately.
    if ($this->mockResponse->hasError()) {
      throw $this->mockResponse->createError();
    }

    // Text response: split into word tokens and stream with delay.
    if ($this->mockResponse->text !== '') {
      $words = preg_split('/(\s+)/', $this->mockResponse->text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
      $totalWords = count($words);

      foreach ($words as $index => $word) {
        $isLast = ($index === $totalWords - 1);
        $metadata = [];

        // Set token usage on the last chunk.
        $streamedMessage = $this->createStreamedChatMessage(
          'assistant',
          $word,
          $metadata,
          NULL,
          [],
        );

        if ($isLast) {
          $this->setFinishReason('stop');
          if ($this->mockResponse->tokenUsage !== NULL) {
            $streamedMessage->setInputTokenUsage($this->mockResponse->tokenUsage['input'] ?? 0);
            $streamedMessage->setOutputTokenUsage($this->mockResponse->tokenUsage['output'] ?? 0);
          }
        }

        yield $streamedMessage;

        // Delay between tokens (skip delay after last token).
        if (!$isLast && $this->mockResponse->delay > 0) {
          usleep($this->mockResponse->delay);
        }
      }

      return;
    }

    // Tool-call response: yield a single empty chunk. Tool calls
    // are returned via getTools() as proper ToolsFunctionOutput
    // objects, bypassing assembleToolCalls().
    if ($this->mockResponse->toolCalls !== NULL) {
      $streamedMessage = $this->createStreamedChatMessage(
        'assistant',
        '',
        [],
        NULL,
        [],
      );

      $this->setFinishReason('tool_calls');

      if ($this->mockResponse->tokenUsage !== NULL) {
        $streamedMessage->setInputTokenUsage($this->mockResponse->tokenUsage['input'] ?? 0);
        $streamedMessage->setOutputTokenUsage($this->mockResponse->tokenUsage['output'] ?? 0);
      }

      yield $streamedMessage;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Builds ToolsFunctionOutput objects directly from the mock response
   * instead of relying on assembleToolCalls(), which expects the
   * OpenAI incremental delta format with toArray() on each chunk.
   */
  public function getTools(): array {
    if ($this->mockResponse->toolCalls === NULL) {
      return [];
    }

    $tools = [];
    foreach ($this->mockResponse->toolCalls as $toolCall) {
      $fn = $toolCall['function'] ?? [];
      $arguments = $fn['arguments'] ?? [];
      // Arguments may be a JSON string or an already-decoded array.
      if (is_string($arguments)) {
        $arguments = json_decode($arguments, TRUE) ?? [];
      }
      $output = new ToolsFunctionOutput(
        NULL,
        $toolCall['id'] ?? '',
        $arguments,
      );
      $output->setName($fn['name'] ?? '');
      $tools[] = $output;
    }

    return $tools;
  }

}
