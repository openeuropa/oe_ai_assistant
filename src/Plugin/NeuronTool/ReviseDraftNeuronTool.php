<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\Drafting\DraftCollector;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Applies a requested change to a stored draft.
 *
 * The groups the change affects are drafted again from their current
 * values; every other group is carried over untouched. The result is the
 * next version, grouped under the draft it started from, so the editor can
 * compare it with the original.
 */
#[NeuronTool(
  id: 'revise_draft',
  description: 'Applies a change the user asked for to a draft that already exists,'
  . ' instead of writing a new one. Name the groups the change affects;'
  . ' every other group is carried over untouched. The result is the'
  . ' next version, named after the draft it revises, such as "Draft'
  . ' 2.1" for the first revision of "Draft 2.0".',
  label: new TranslatableMarkup('Revise draft'),
)]
final class ReviseDraftNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DraftHistoryInterface $draftHistory,
    private readonly DraftingSchemaProviderInterface $schemaProvider,
    private readonly DraftingTurn $turn,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      new ToolProperty(
        'instruction',
        PropertyType::STRING,
        'The change to apply, in the terms the user asked for it.',
        TRUE,
      ),
      new ArrayProperty(
        'groups',
        'The ids of the field groups the change is limited to. Leave it out'
        . ' to apply the change to the whole draft, which is what a request'
        . ' that does not point at particular fields means.',
        FALSE,
        new ToolProperty('group', PropertyType::STRING),
      ),
      new ToolProperty(
        'version',
        PropertyType::INTEGER,
        'The version to revise. Defaults to the most recent draft.',
      ),
    ];
  }

  /**
   * Revises the named groups of a stored draft and versions the result.
   */
  public function __invoke(string $instruction, ?array $groups = NULL, ?int $version = NULL): string {
    $session = $this->turn->session();
    $drafts = $this->draftHistory->listDrafts($session);
    if ($drafts === []) {
      return json_encode(['error' => 'No draft has been generated yet, so there is nothing to revise.']);
    }
    $version ??= (int) end($drafts)['version'];

    $base = $this->draftHistory->getDraftContent($session, $version);
    if ($base === NULL) {
      return json_encode([
        'error' => sprintf(
          'No draft carries version %d. The drafts are: %s.',
          $version,
          implode(', ', array_column($drafts, 'name')),
        ),
      ]);
    }

    // The draft carries the groups it was written against, so a revision
    // keeps its structure whatever the session points at now. Drafts stored
    // before the groups travelled with them fall back to their template.
    $collector = new DraftCollector(
      $base['context']['groups'] ?? $this->schemaProvider->groups(
        $this->turn->entityTypeId(),
        $this->turn->bundle(),
        $base['templateId'],
      ),
      fn (array $fields): array => $this->turn->version($fields, $version, $base['context']),
    );
    // Without named groups the whole draft is revised, so a change meant
    // for every field reaches the groups this draft has rather than the
    // ones the session points at now.
    $revise = $groups === NULL || $groups === [] ? $collector->groupIds() : array_values($groups);
    $unknown = array_diff($revise, $collector->groupIds());
    if ($unknown !== []) {
      return json_encode([
        'error' => sprintf(
          'Draft version %d has no group %s. Its groups are: %s.',
          $version,
          implode(', ', $unknown),
          implode(', ', $collector->groupIds()),
        ),
      ]);
    }

    $collector->seedFrom($base['fields'], $revise);
    foreach ($revise as $groupId) {
      $definition = $collector->group($groupId);
      $collector->add($groupId, $this->turn->draft(
        $groupId,
        $definition['schemaSlice'],
        $this->task($collector->valuesOf($groupId, $base['fields']), $instruction),
      ));
    }

    return json_encode([
      'revised' => $revise,
      'groups' => $collector->groupIds(),
      'revisionOf' => $version,
      'draft' => $collector->draft(),
    ]);
  }

  /**
   * Builds the task that asks one group for the requested change.
   */
  private function task(array $current, string $instruction): string {
    return "Current values:\n" . json_encode($current) . "\n\n"
      . "Requested change:\n" . $instruction . "\n\n"
      . 'Return the complete group with that change applied,'
      . ' and every other value exactly as it is now.';
  }

}
