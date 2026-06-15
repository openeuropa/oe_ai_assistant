<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Generic multi-turn LLM tool execution loop.
 *
 * Reusable by any plugin that needs multi-turn tool interactions
 * with streaming. The loop is plugin-agnostic: callers provide
 * their own provider, tools, terminal tool names, and tags.
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
   *   caller handles these tools (e.g. triggering orchestration).
   * @param string[] $tags
   *   Provider tags for logging and filtering (e.g. ['drafting']).
   * @param array $fixedToolContexts
   *   Context values fixed by the caller, keyed by tool name and
   *   then by context name. Fixed properties are removed from the
   *   tool definitions advertised to the LLM and forced at
   *   execution time, so neither the LLM nor a user steering it
   *   can call the tool outside the caller's allowed scope.
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
    array $terminalToolNames = [],
    array $tags = [],
    array $fixedToolContexts = [],
  ): ToolLoopResult;

}
