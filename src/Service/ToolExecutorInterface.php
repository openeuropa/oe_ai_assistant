<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Interface for executing FunctionCall tool calls.
 *
 * Abstracts the FunctionCallPluginManager so the ToolExecutionLoop
 * can be unit tested with a mock executor. The real implementation
 * delegates to the plugin manager; tests provide a simple stub.
 */
interface ToolExecutorInterface {

  /**
   * Executes a tool call and returns the result.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface $toolCall
   *   The tool call from the LLM response.
   *
   * @return string
   *   JSON-encoded tool result for the conversation history.
   */
  public function execute($toolCall): string;

}
