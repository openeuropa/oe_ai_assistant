<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

/**
 * Where a workflow leaves the answer of the deferred call it ran for.
 *
 * A deferred tool runs no code, so a run of one of this module's workflows
 * does its work instead. The run and the node that started it are different
 * plugins, and a top-level run reports nothing but its state, so the state
 * carries the answer.
 *
 * The node that starts a workflow reads one key whichever workflow it was,
 * so the key belongs to neither end. Both of them name it from here.
 */
interface DeferredWorkflowInterface {

  /**
   * The state key the run leaves the answer under.
   *
   * The value is what Neuron submits as the result of a deferred call. One
   * result the model reads, or one error.
   */
  public const string RESULT_KEY = 'result';

}
