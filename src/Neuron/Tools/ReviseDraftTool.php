<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory;
use Drupal\oe_ai_assistant\Service\Drafting\DraftCollector;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Tool applying a requested change to a stored draft.
 *
 * The groups the change affects are drafted again from their current
 * values; every other group is carried over untouched. The result is the
 * next version, grouped under the draft it started from, so the editor can
 * compare it with the original.
 */
final class ReviseDraftTool extends Tool {

  public const NAME = 'revise_draft';

  /**
   * ReviseDraftTool constructor.
   *
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface $draftHistory
   *   The draft history reader.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The editorial session holding the drafts.
   * @param \Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory $conversation
   *   The conversation the revised rows nest under.
   * @param \Closure $groupsFor
   *   Returns the schema groups of a template, called with its id or NULL.
   * @param \Closure $reviser
   *   Drafts one group, called with the group id, the schema slice, the task
   *   prompt and the parent turn, and returning the decoded field values.
   * @param \Closure $versionDraft
   *   Versions the consolidated fields, called with them, the version they
   *   revise and the context to inherit.
   */
  public function __construct(
    private readonly DraftHistoryInterface $draftHistory,
    private readonly AiEditorialSessionInterface $session,
    private readonly ConversationChatHistory $conversation,
    private readonly \Closure $groupsFor,
    private readonly \Closure $reviser,
    private readonly \Closure $versionDraft,
  ) {
    parent::__construct(
      self::NAME,
      'Applies a change the user asked for to a draft that already exists,'
      . ' instead of writing a new one. Name the groups the change affects;'
      . ' every other group is carried over untouched. The result is the'
      . ' next version, named after the draft it revises, such as "Draft'
      . ' 2.1" for the first revision of "Draft 2.0".',
    );
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
    $drafts = $this->draftHistory->listDrafts($this->session);
    if ($drafts === []) {
      return json_encode(['error' => 'No draft has been generated yet, so there is nothing to revise.']);
    }
    $version ??= (int) end($drafts)['version'];

    $base = $this->draftHistory->getDraftContent($this->session, $version);
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
      $base['context']['groups'] ?? ($this->groupsFor)($base['templateId']),
      fn (array $fields): array => ($this->versionDraft)($fields, $version, $base['context']),
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
      $collector->add($groupId, ($this->reviser)(
        $groupId,
        $definition['schemaSlice'],
        $this->task($collector->valuesOf($groupId, $base['fields']), $instruction),
        $this->conversation->lastAssistant(),
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
