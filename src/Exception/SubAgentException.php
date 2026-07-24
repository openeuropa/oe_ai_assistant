<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * Thrown when a drafting sub-agent fails to produce a usable answer.
 *
 * Raised for every way a schema group can come back empty: a solvability
 * verdict that yields no answer, a blank response, or output that is not
 * parseable JSON. The orchestrator catches it per group, so one failed group
 * is recorded as an error turn and the remaining groups still run.
 *
 * The underlying cause is often not available: ai_agents catches a failing
 * provider call, dispatches AgentFinishedExecutionEvent and returns
 * JOB_NOT_SOLVABLE without rethrowing or logging the exception.
 *
 * @see \Drupal\oe_ai_assistant\Service\DraftingOrchestrator
 */
class SubAgentException extends \RuntimeException {

}
