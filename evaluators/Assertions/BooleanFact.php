<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Evaluators\Assertions;

use NeuronAI\Evaluation\Assertions\AbstractAssertion;
use NeuronAI\Evaluation\AssertionResult;

/**
 * Asserts on a boolean the evaluator worked out itself.
 *
 * Some checks are plain facts about stored data, such as whether a field
 * changed between two drafts. There is nothing there to judge or to match
 * as a string, so we wrap the boolean as an assertion and it shows up in
 * the report alongside the rest.
 */
final class BooleanFact extends AbstractAssertion {

  /**
   * Builds the assertion.
   *
   * @param string $failureMessage
   *   What to report when the fact does not hold.
   */
  public function __construct(
    private readonly string $failureMessage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function evaluate(mixed $actual): AssertionResult {
    if (!is_bool($actual)) {
      throw new \InvalidArgumentException(static::class . ' evaluates a boolean, got ' . get_debug_type($actual));
    }

    return $actual ? AssertionResult::pass(1.0) : AssertionResult::fail(0.0, $this->failureMessage);
  }

}
