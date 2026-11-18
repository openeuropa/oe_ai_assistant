<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Workflow;

/**
 * A tool plugin whose work is a workflow, run where the call is executed.
 *
 * A tool runs between two yields of the agent that called it, so a tool that
 * does its own work cannot report progress. WorkflowToolNode runs the
 * workflow of such a tool instead, and what the run reports travels on into
 * the stream of the turn.
 *
 * The run answers the call, so it must finish inside the request: a workflow
 * that pauses leaves a call nothing can settle, and is reported to the model
 * as a failed tool.
 */
interface WorkflowToolInterface {

  /**
   * The run that produces the result of one call.
   *
   * Called once per call, so the run reads the id and the inputs of the call
   * it answers.
   *
   * @param \NeuronAI\Tools\ToolCall $call
   *   The call to answer.
   *
   * @return \NeuronAI\Workflow\Workflow
   *   The run, built and ready.
   */
  public function workflow(ToolCall $call): Workflow;

}
