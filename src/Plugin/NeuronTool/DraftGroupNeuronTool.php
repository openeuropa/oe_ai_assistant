<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationMessageStore;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

/**
 * Drafts the field values of one schema group.
 *
 * Each call runs a drafter agent for the group and hands the values to the
 * collector. The call that completes the set carries the versioned draft in
 * its result, so the model and the editor learn the draft number from the
 * same place.
 */
#[NeuronTool(
  id: 'draft_group',
  description: 'Drafts the field values of one field group through a sub-agent.'
  . ' The result lists the groups still pending; the call that completes'
  . ' the set carries the versioned draft. Drafts are named "Draft 1.0",'
  . ' "Draft 2.0" and so on; get_draft_history lists their names.',
  label: new TranslatableMarkup('Draft group'),
)]
final class DraftGroupNeuronTool extends NeuronToolPluginBase {

  // Each group counts as its own run, so drafting many groups in one turn
  // stays within the per-tool run limit.
  use TrackByInputs;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DraftingTurn $turn,
    private readonly ConversationMessageStore $store,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      new ToolProperty(
        'group',
        PropertyType::STRING,
        'The id of the field group to draft.',
        TRUE,
        $this->turn->collector()->groupIds(),
      ),
    ];
  }

  /**
   * Drafts the group and reports the values, pending groups and draft.
   */
  public function __invoke(string $group): string {
    $collector = $this->turn->collector();
    $definition = $collector->group($group);
    if ($definition === NULL) {
      return json_encode([
        'error' => sprintf('Unknown group "%s". The groups are: %s.', $group, implode(', ', $collector->groupIds())),
      ]);
    }

    $fields = $this->turn->draft($group, $definition['schemaSlice'], $this->task(), $this->parentTurn());
    $collector->add($group, $fields);

    $result = [
      'group' => $group,
      'label' => $definition['label'],
      'fields' => $fields,
      'pending' => $collector->pending(),
    ];
    $draft = $collector->draft();
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
    foreach ($this->store->loadActive($this->turn->threadId()) as $message) {
      foreach ($message->getTextBlocks() as $block) {
        $lines[] = $message->getRole() . ': ' . $block->content;
      }
    }

    $task = "Conversation context:\n" . implode("\n", $lines) . "\n";
    $mainFields = $this->turn->collector()->mainFields();
    if ($mainFields !== NULL) {
      $task .= "Main fields already generated:\n" . json_encode($mainFields) . "\n\n";
    }

    return $task . 'Generate content for the fields in the provided schema. Follow the conversation context.';
  }

  /**
   * Returns the assistant turn that asked for the group, if recorded.
   */
  private function parentTurn(): ?AiConversationMessageInterface {
    return $this->store->lastAssistant($this->turn->threadId());
  }

}
