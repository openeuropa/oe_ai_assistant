<?php

/**
 * @file
 * Output configuration for the Neuron AI evaluation runner.
 *
 * The runner reads this file from the working directory, and drush runs the
 * evaluate command from the Drupal root. "ddev install" links it there.
 *
 * @see https://docs.neuron-ai.dev/agent/evaluation
 */

declare(strict_types=1);

use NeuronAI\Evaluation\Output\ConsoleOutput;

return [
  // A failed judgement is only actionable with the answer that earned it,
  // so the console driver runs verbose: it prints the run output, the
  // expected value and the reasoning of every assertion that failed.
  'output' => [
    new ConsoleOutput(TRUE),
  ],
];
