<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use NeuronAI\Chat\History\MessageStoreInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\EditorialMessageStore;
use Drupal\oe_ai_assistant\Service\Drafting\DraftCollector;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface;
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
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class DraftGroupNeuronTool extends DraftingToolBase {

  // Each group counts as its own run, so drafting many groups in one turn
  // stays within the per-tool run limit.
  use TrackByInputs;

  /**
   * What each call of this run has drafted, gathered on first use.
   *
   * Neuron clones the registered tool for every call so the inputs of one call
   * cannot reach another, and the clone keeps pointing at this plugin. So the
   * results of the run live here.
   */
  private ?DraftCollector $collector = NULL;

  /**
   * The versioned draft, once every group has been drafted.
   */
  private ?array $draft = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DraftingBriefInterface $brief,
    NeuronAgentManagerInterface $agents,
    private readonly MessageStoreInterface $store,
    private readonly DraftHistoryInterface $draftHistory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $brief, $agents);
  }

  /**
   * Drafts the group and reports the values, pending groups and draft.
   */
  public function __invoke(string $group): string {
    $collector = $this->collector();
    $definition = $collector->group($group);
    if ($definition === NULL) {
      return json_encode([
        'error' => sprintf('Unknown group "%s". The groups are: %s.', $group, implode(', ', $collector->groupIds())),
      ]);
    }

    $fields = $this->draftGroup($group, $definition['schemaSlice'], $this->task());
    $collector->add($group, $fields);

    $result = [
      'group' => $group,
      'label' => $definition['label'],
      'fields' => $fields,
      'pending' => $collector->pending(),
    ];
    $draft = $this->draft();
    if ($draft !== NULL) {
      $result['draft'] = $draft;
    }

    return json_encode($result);
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
        $this->collector()->groupIds(),
      ),
    ];
  }

  /**
   * The collector every call of this run adds its group to.
   */
  private function collector(): DraftCollector {
    return $this->collector ??= new DraftCollector($this->brief->groups($this->session()));
  }

  /**
   * Versions the draft once every group is drafted, else answers NULL.
   *
   * The draft is versioned once, so a group drafted again after the set was
   * complete reports the draft that set produced rather than a new one.
   */
  private function draft(): ?array {
    if ($this->draft === NULL && $this->collector()->complete()) {
      $this->draft = $this->draftHistory->nextVersion($this->session()) + [
        'context' => $this->draftContext(),
        'fields' => $this->collector()->fields(),
      ];
    }

    return $this->draft;
  }

  /**
   * Builds the drafter's task from the conversation and the main fields.
   */
  private function task(): string {
    $lines = [];
    // What the editor and the agent said, read from the thread the drafting
    // agent holds its conversation under: the session and then the agent.
    $thread = EditorialMessageStore::SESSION_PREFIX . $this->session()->id()
      . '.' . EditorialMessageStore::AGENT_PREFIX . 'drafting';
    foreach ($this->store->loadActive($thread) as $message) {
      foreach ($message->getTextBlocks() as $block) {
        $lines[] = $message->getRole() . ': ' . $block->content;
      }
    }

    $task = "Conversation context:\n" . implode("\n", $lines) . "\n";
    $mainFields = $this->collector()->mainFields();
    if ($mainFields !== NULL) {
      $task .= "Main fields already generated:\n" . json_encode($mainFields) . "\n\n";
    }

    return $task . 'Generate content for the fields in the provided schema. Follow the conversation context.';
  }

}
