<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Turns the schema groups into a plan with every step pending.
 */
final class PlanNode extends Node {

  /**
   * {@inheritdoc}
   */
  public function __invoke(StartEvent $event, WorkflowState $state): \Generator {
    $groups = $state->get(DraftingWorkflow::GROUPS, []);
    if ($groups === []) {
      yield new TextChunk(UniqueIdGenerator::generateId('msg_'), 'No fields available for drafting.');
      $state->set(DraftingWorkflow::FIELDS, []);
      return new StopEvent();
    }

    $plan = array_map(static fn (array $group): array => [
      'stepId' => $group['groupId'],
      'label' => $group['label'],
      'status' => 'pending',
    ], $groups);
    $state->set(DraftingWorkflow::PLAN, $plan);
    yield new PlanChunk($plan);

    return new DraftGroupsEvent();
  }

}
