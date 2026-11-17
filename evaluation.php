<?php

/**
 * @file
 * Config for the neuron CLI's evaluation command.
 *
 * The CLI looks for this file in its working directory, and
 * evaluators/bootstrap.php has already switched that to web/, so it leaves
 * a shim there pointing back here.
 */

declare(strict_types=1);

use Drupal\oe_ai_assistant\Evaluators\ConciseFailureOutput;

return [
  // Verbose is on for the per-assertion reasoning text. The same flag also
  // prints an Input/Output dump for every failure, which is the part
  // ConciseFailureOutput drops.
  'output' => [new ConciseFailureOutput(verbose: TRUE)],
];
