<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Workflow;

/**
 * A tool whose work is a workflow, run in place of the call.
 *
 * Work that takes minutes goes in a workflow, which can report progress
 * while it runs. WorkflowToolNode drives that run where it would otherwise
 * execute the tool.
 *
 * The run has to answer the call inside the request. A run that pauses
 * leaves the call with no result, so the node refuses it and the call fails.
 */
interface WorkflowToolInterface {

  /**
   * Builds the workflow that answers one call.
   *
   * The workflow needs an id of its own. The turn is itself a run, and the
   * node refuses a workflow that comes back under the turn's id.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The call to answer.
   *
   * @return \NeuronAI\Workflow\Workflow
   *   The workflow, built and ready to run.
   */
  public function workflow(ToolCall $call): Workflow;

}
