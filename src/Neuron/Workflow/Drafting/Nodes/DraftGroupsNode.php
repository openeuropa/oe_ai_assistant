<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Nodes;

use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Chunks\GroupErrorChunk;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Chunks\PlanChunk;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\DraftingTurnWorkflow;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Events\ConsolidateEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Events\DraftGroupsEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Runs the drafter on every group in order, reporting progress per step.
 *
 * A failing group is reported and skipped; the remaining groups still run.
 * The main fields go first so later groups can build on them.
 */
final class DraftGroupsNode extends Node {

  /**
   * DraftGroupsNode constructor.
   *
   * @param \Closure $draftGroup
   *   Drafts one group, called with the step id, the schema slice, the task
   *   prompt and the parent turn, and returning the decoded field values.
   */
  public function __construct(
    private readonly \Closure $draftGroup,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function __invoke(DraftGroupsEvent $event, WorkflowState $state): \Generator {
    $plan = $state->get(DraftingTurnWorkflow::PLAN, []);
    $results = [];
    $mainFieldsResult = '';

    foreach ($state->get(DraftingTurnWorkflow::GROUPS, []) as $index => $group) {
      $stepId = $group['groupId'];
      $plan[$index]['status'] = 'in_progress';
      yield new PlanChunk($plan);

      try {
        $results[$stepId] = ($this->draftGroup)(
          $stepId,
          $group['schemaSlice'],
          $this->task($stepId, $state->get(DraftingTurnWorkflow::CONTEXT, ''), $mainFieldsResult),
          $state->get(DraftingTurnWorkflow::PARENT),
        );
        if ($stepId === 'main_fields') {
          $mainFieldsResult = json_encode($results[$stepId]);
        }
        $plan[$index]['status'] = 'done';
        yield new PlanChunk($plan);
      }
      catch (\Throwable $e) {
        $plan[$index]['status'] = 'error';
        yield new PlanChunk($plan);
        yield new GroupErrorChunk($stepId, $e->getMessage());
      }
    }

    $state->set(DraftingTurnWorkflow::RESULTS, $results);

    return new ConsolidateEvent();
  }

  /**
   * Builds the task prompt of one group.
   */
  private function task(string $stepId, string $conversationContext, string $mainFieldsResult): string {
    $task = "Conversation context:\n$conversationContext\n";
    if ($stepId !== 'main_fields' && $mainFieldsResult !== '') {
      $task .= "Main fields already generated:\n$mainFieldsResult\n\n";
    }
    return $task . 'Generate content for the fields in the provided schema. Follow the conversation context.';
  }

}
