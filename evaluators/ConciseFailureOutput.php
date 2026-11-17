<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators;

use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Runner\EvaluationResults;

use function count;
use function round;
use function sprintf;

/**
 * Verbose console output without the Input/Output dump on failures.
 */
final class ConciseFailureOutput extends ConsoleOutput {

  /**
   * {@inheritdoc}
   *
   * The parent's method without its `if ($this->verbose)` block, which
   * dumps the dataset item and the full run() output for every failure.
   * That dump and the per-assertion reasoning lines in
   * printAssertionFailureSummary() both hang off $verbose, so overriding
   * this one method is the only way to keep the reasoning and lose the dump.
   */
  protected function printFailures(EvaluationResults $results, array $evaluatorLabels): void {
    echo 'There were ' . $results->getFailedCount() . " failure(s):\n\n";

    $failureCount = 1;
    foreach ($results->getFailedResults() as $result) {
      echo "{$failureCount}) {$evaluatorLabels[$result->getEvaluatorClass()]} #{$result->getIndex()}\n";

      echo $result->hasError()
        ? '   Error: ' . $this->escapeControlCharacters((string) $result->getError()) . "\n"
        : "   Evaluation failed\n";

      if ($result->getTotalAssertions() > 0) {
        echo sprintf(
          "   Assertions: %d passed, %d failed\n",
          $result->getAssertionsPassed(),
          $result->getAssertionsFailed(),
        );
      }

      if ($result->getReruns() !== []) {
        echo sprintf(
          "   Re-runs: %d of %d passed%s\n",
          $result->getRerunsPassed(),
          count($result->getReruns()),
          $result->getRerunsErrored() > 0 ? sprintf(', %d errored', $result->getRerunsErrored()) : '',
        );
      }

      echo '   Execution Time: ' . round($result->getExecutionTime(), 3) . "s\n\n";
      $failureCount++;
    }
  }

}
