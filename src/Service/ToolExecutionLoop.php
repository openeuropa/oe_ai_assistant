<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;

/**
 * Generic multi-turn LLM tool loop with streaming.
 *
 * Reusable by any plugin that needs multi-turn tool interactions.
 * Calls the LLM, streams the response, checks for tool calls,
 * executes non-terminal tools, feeds results back to the LLM,
 * and repeats. Stops when:
 * - A "terminal tool" is called -- the caller handles what
 *   happens next (e.g. orchestration, save, export).
 * - The LLM responds with text (no tool calls).
 * - The maximum iteration count is reached.
 *
 * The loop is plugin-agnostic: the caller provides the provider,
 * model, tools, terminal tool names, and tags. No drafting-
 * specific logic lives here.
 */
class ToolExecutionLoop implements ToolExecutionLoopInterface {

  /**
   * Maximum LLM call iterations to prevent infinite loops.
   */
  private const MAX_ITERATIONS = 5;

  /**
   * Constructs a ToolExecutionLoop.
   *
   * @param \Drupal\oe_ai_assistant\Service\ToolExecutorInterface $toolExecutor
   *   Executes non-terminal tool calls and returns results.
   */
  public function __construct(
    private readonly ToolExecutorInterface $toolExecutor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(
    object $provider,
    string $modelId,
    string $systemPrompt,
    array $tools,
    array &$history,
    UiMessageStreamInterface $stream,
    array $terminalToolNames = [],
    array $tags = [],
    array $fixedToolContexts = [],
  ): ToolLoopResult {

    // Remove caller-fixed properties from the tool definitions sent
    // to the LLM: their values are injected at execution time, so
    // the LLM must not be able to supply (or be tricked into
    // supplying) them.
    foreach ($tools as $tool) {
      $fixed = $fixedToolContexts[$tool->getName()] ?? [];
      foreach (array_keys($fixed) as $propertyName) {
        $tool->unsetProperty($propertyName);
      }
    }

    for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
      // Build ChatInput with current conversation history.
      $chatInput = new ChatInput($history);
      $chatInput->setStreamedOutput(TRUE);
      $chatInput->setSystemPrompt($systemPrompt);
      $chatInput->setChatTools(new ToolsInput($tools));

      $chatOutput = $provider->chat(
        $chatInput, $modelId, $tags
      );

      // Stream the LLM response and collect tool calls.
      $toolCalls = $stream->streamChatOutput($chatOutput, 'router');

      // No tool calls: text response.
      if (empty($toolCalls)) {
        $responseText = $this->extractResponseText($chatOutput);
        if ($responseText !== '') {
          $history[] = new ChatMessage('assistant', $responseText);
        }
        return new ToolLoopResult('stop', '', $responseText);
      }

      // Check for terminal tools.
      foreach ($toolCalls as $tool) {
        if (in_array($tool->getName(), $terminalToolNames, TRUE)) {
          return new ToolLoopResult(
            'tool_calls',
            $tool->getName(),
          );
        }
      }

      // Execute non-terminal tools and feed results back.
      $assistantMsg = new ChatMessage('assistant', '');
      $assistantMsg->setTools($toolCalls);
      $history[] = $assistantMsg;

      foreach ($toolCalls as $toolCall) {
        $result = $this->toolExecutor->execute(
          $toolCall,
          $fixedToolContexts[$toolCall->getName()] ?? [],
        );
        $toolResultMsg = new ChatMessage('tool', $result);
        $toolResultMsg->setToolsId($toolCall->getToolId());
        $history[] = $toolResultMsg;
      }
    }

    // Safety limit reached.
    return new ToolLoopResult('max_iterations');
  }

  /**
   * Extracts the text response from a ChatOutput after streaming.
   *
   * After streamChatOutput() has consumed the stream, the
   * normalized output can be reconstructed to get the full text.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $chatOutput
   *   The chat output from the provider.
   *
   * @return string
   *   The assistant's text response, or empty string.
   */
  private function extractResponseText($chatOutput): string {
    $normalized = $chatOutput->getNormalized();
    if ($normalized instanceof StreamedChatMessageIteratorInterface) {
      $output = $normalized->reconstructChatOutput();
      return $output->getNormalized()->getText() ?? '';
    }
    return $normalized->getText() ?? '';
  }

}
