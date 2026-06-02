<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Interface for the multi-turn LLM tool execution loop.
 *
 * Calls the LLM, streams the response, checks for tool calls,
 * executes non-terminal tools, feeds results back, and repeats
 * until a terminal condition is reached.
 */
interface ToolExecutionLoopInterface {

  /**
   * Runs the tool execution loop.
   *
   * @param object $provider
   *   The AI provider instance (from AiProviderPluginManager).
   * @param string $modelId
   *   The model ID (e.g. "gpt-4o").
   * @param string $systemPrompt
   *   The system prompt for the LLM.
   * @param array $tools
   *   Array of ToolsFunctionInput objects (the available tools).
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $history
   *   The conversation history (mutated in place as the loop
   *   adds assistant and tool messages).
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The SSE stream for emitting text-delta events.
   * @param string[] $terminalToolNames
   *   Tool names that should stop the loop when called. The
   *   caller handles these tools (e.g. triggering orchestration
   *   for 'draft_content'). Defaults to ['draft_content'].
   *
   * @return \Drupal\oe_ai_assistant\Service\ToolLoopResult
   *   Describes how the loop ended.
   */
  public function run(
    object $provider,
    string $modelId,
    string $systemPrompt,
    array $tools,
    array &$history,
    UiMessageStreamInterface $stream,
    array $terminalToolNames = ['draft_content'],
  ): ToolLoopResult;

}
