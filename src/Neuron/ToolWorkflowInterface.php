<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

/**
 * Where the workflow of a tool leaves the result of the call it answered.
 *
 * A top-level run reports nothing but its state, so a workflow that answers
 * a tool call writes the result into the state. The tool node reads one key
 * whichever workflow ran, and the two are different plugins, so the key
 * belongs to neither of them. Both name it from here.
 */
interface ToolWorkflowInterface {

  /**
   * The state key the run leaves the result under.
   *
   * The value is what the model reads as the result of the call. One result,
   * or one error.
   */
  public const string RESULT_KEY = 'result';

}
