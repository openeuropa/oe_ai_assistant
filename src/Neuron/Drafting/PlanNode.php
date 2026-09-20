<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Turns the schema groups into a plan with every step pending.
 *
 * Without groups the turn still runs to its end, so an empty draft is
 * versioned like any other.
 */
final class PlanNode extends Node {

  /**
   * {@inheritdoc}
   */
  public function __invoke(DraftRequestedEvent $event, WorkflowState $state): \Generator {
    $groups = $state->get(DraftingTurnWorkflow::GROUPS, []);
    if ($groups === []) {
      yield new TextChunk(UniqueIdGenerator::generateId('msg_'), 'No fields available for drafting.');
    }

    $plan = array_map(static fn (array $group): array => [
      'stepId' => $group['groupId'],
      'label' => $group['label'],
      'status' => 'pending',
    ], $groups);
    $state->set(DraftingTurnWorkflow::PLAN, $plan);
    if ($plan !== []) {
      yield new PlanChunk($plan);
    }

    return new DraftGroupsEvent();
  }

}
