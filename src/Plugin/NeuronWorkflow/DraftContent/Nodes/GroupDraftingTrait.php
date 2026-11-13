<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\Nodes;

use Drupal\oe_ai_assistant\Neuron\Chat\Messages\EditorialAssistantMessage;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Nodes\ValidateNode;

/**
 * The drafting steps DraftContentNode and AmendContentNode both need.
 */
trait GroupDraftingTrait {

  /**
   * Runs the drafter of one group and reports the values it wrote.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources $resources
   *   What this run shares.
   * @param array $group
   *   The group definition, with its id and its schema slice.
   * @param string $run
   *   The run this draft belongs to.
   * @param string $task
   *   What to tell the drafter.
   *
   * @return array
   *   The field values the drafter wrote.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException
   *   When no answer of the drafter matched the group schema.
   */
  private function draftGroup(DraftContentResources $resources, array $group, string $run, string $task): array {
    $state = $resources->workflows->createWorkflow('draft_group', [], [
      'session' => $resources->session,
      'group' => (string) $group['groupId'],
      'schema' => (array) $group['schemaSlice'],
      'task' => $task,
      'run' => $run,
    ])->run();

    return (array) $state->get(ValidateNode::FIELDS_KEY, []);
  }

  /**
   * Keeps the draft in a thread of its own.
   *
   * No model has to read a draft back, and no agent runs on this thread, so
   * a record can be as large as the content type needs. The message names
   * its version and the store writes that to the row's own column, so a
   * reader can ask for a single draft.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources $resources
   *   What this run shares.
   * @param array $draft
   *   The record of the run: its version, the run that wrote it, its status,
   *   the snapshot of what the editor set up, the groups with the status each
   *   one reached, the schema groups it was written against, and the field
   *   values.
   */
  private function storeDraft(DraftContentResources $resources, array $draft): void {
    $message = (new EditorialAssistantMessage(json_encode($draft)))
      ->setDraftVersion((string) $draft['version']);

    $resources->store->append($resources->session->draftsThread(), $message);
  }

  /**
   * Formats the session's conversation for a drafter's prompt.
   *
   * This reads the editorial thread only. The drafts don't show up in it,
   * because each group's drafter writes to a thread of its own.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources $resources
   *   What this run shares.
   *
   * @return string
   *   The conversation block, empty when the session has no turns yet.
   */
  private function conversation(DraftContentResources $resources): string {
    $turns = $resources->session->conversationTurns();
    if ($turns === []) {
      return '';
    }

    return "The conversation so far:\n" . implode("\n", array_map(
      static fn (array $turn): string => sprintf(
        '%s: %s',
        $turn['role'] === 'user' ? 'Editor' : 'Assistant',
        $turn['text'],
      ),
      $turns,
    )) . "\n\n";
  }

}
