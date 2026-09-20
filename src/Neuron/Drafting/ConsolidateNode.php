<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Merges the group results into one field map.
 *
 * Main fields merge flat, restricted to the group's own fields. A reference
 * group is read by field name; a bare item list is accepted as that field.
 */
final class ConsolidateNode extends Node {

  /**
   * {@inheritdoc}
   */
  public function __invoke(ConsolidateEvent $event, WorkflowState $state): \Generator {
    $results = $state->get(DraftingTurnWorkflow::RESULTS, []);
    $fields = [];

    foreach ($state->get(DraftingTurnWorkflow::GROUPS, []) as $group) {
      $stepId = $group['groupId'];
      if (!isset($results[$stepId])) {
        continue;
      }
      if ($stepId === 'main_fields') {
        $fields = array_merge(
          $fields,
          array_intersect_key($results[$stepId], array_flip($group['fieldNames'])),
        );
        continue;
      }
      foreach ($group['fieldNames'] as $fieldName) {
        if (array_key_exists($fieldName, $results[$stepId])) {
          $fields[$fieldName] = $results[$stepId][$fieldName];
        }
        elseif (array_is_list($results[$stepId])) {
          $fields[$fieldName] = $results[$stepId];
        }
      }
    }

    $state->set(DraftingTurnWorkflow::FIELDS, $fields);
    yield new DraftedFieldsChunk($fields);

    return new VersionEvent();
  }

}
