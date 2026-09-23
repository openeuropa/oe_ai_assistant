<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory;
use Drupal\oe_ai_assistant\Service\Drafting\DraftCollector;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

/**
 * Tool drafting the field values of one schema group.
 *
 * Each call runs a drafter sub-agent for the group and hands the values to
 * the collector. The call that completes the set carries the versioned
 * draft in its result, so the model and the editor learn the draft number
 * from the same place.
 */
final class DraftGroupTool extends Tool implements HasRunKey {

  // Each group counts as its own run, so drafting many groups in one turn
  // stays within the per-tool run limit.
  use TrackByInputs;

  public const NAME = 'draft_group';

  /**
   * DraftGroupTool constructor.
   *
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftCollector $collector
   *   The collector of this turn's group results.
   * @param \Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory $conversation
   *   The conversation the drafters take their context from.
   * @param \Closure $drafter
   *   Drafts one group, called with the group id, the schema slice, the task
   *   prompt and the parent turn, and returning the decoded field values.
   */
  public function __construct(
    private readonly DraftCollector $collector,
    private readonly ConversationChatHistory $conversation,
    private readonly \Closure $drafter,
  ) {
    parent::__construct(
      self::NAME,
      'Drafts the field values of one field group through a sub-agent.'
      . ' The result lists the groups still pending; the call that completes'
      . ' the set carries the versioned draft. Drafts are named "Draft 1.0",'
      . ' "Draft 2.0" and so on; get_draft_history lists their names.',
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      new ToolProperty('group', PropertyType::STRING, 'The id of the field group to draft.', TRUE, $this->collector->groupIds()),
    ];
  }

  /**
   * Drafts the group and reports the values, the pending groups and the draft.
   */
  public function __invoke(string $group): string {
    $definition = $this->collector->group($group);
    if ($definition === NULL) {
      return json_encode([
        'error' => sprintf('Unknown group "%s". The groups are: %s.', $group, implode(', ', $this->collector->groupIds())),
      ]);
    }

    $fields = ($this->drafter)($group, $definition['schemaSlice'], $this->task(), $this->conversation->lastAssistant());
    $this->collector->add($group, $fields);

    $result = [
      'group' => $group,
      'label' => $definition['label'],
      'fields' => $fields,
      'pending' => $this->collector->pending(),
    ];
    $draft = $this->collector->draft();
    if ($draft !== NULL) {
      $result['draft'] = $draft;
    }
    return json_encode($result);
  }

  /**
   * Builds the drafter's task from the conversation and the main fields.
   */
  private function task(): string {
    $lines = [];
    foreach ($this->conversation->getMessages() as $message) {
      foreach ($message->getTextBlocks() as $block) {
        $lines[] = $message->getRole() . ': ' . $block->content;
      }
    }
    $task = "Conversation context:\n" . implode("\n", $lines) . "\n";
    $mainFields = $this->collector->mainFields();
    if ($mainFields !== NULL) {
      $task .= "Main fields already generated:\n" . json_encode($mainFields) . "\n\n";
    }
    return $task . 'Generate content for the fields in the provided schema. Follow the conversation context.';
  }

}
