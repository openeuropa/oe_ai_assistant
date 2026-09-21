<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

/**
 * Gathers the group results of one drafting turn and versions the draft.
 *
 * One instance per turn, shared by the agent that reads the groups and the
 * tool that drafts them. The results live here rather than on that tool
 * because Neuron hands every tool call a clone of the registered tool:
 * only a shared object carries state from one call to the next.
 *
 * Main fields merge flat, restricted to the group's own fields. A reference
 * group is read by field name; a bare item list is accepted as that field.
 */
final class DraftCollector {

  /**
   * The drafted field values, keyed by group id.
   */
  private array $results = [];

  /**
   * The versioned draft, once every group has been drafted.
   */
  private ?array $draft = NULL;

  /**
   * DraftCollector constructor.
   *
   * @param array $groups
   *   The schema groups, each with groupId, label, fieldNames and
   *   schemaSlice, in drafting order.
   * @param \Closure $versionDraft
   *   Versions and stores the consolidated fields, called with them and
   *   returning the draft shaped {version, context, fields}.
   */
  public function __construct(
    private readonly array $groups,
    private readonly \Closure $versionDraft,
  ) {}

  /**
   * Returns the schema groups, in drafting order.
   */
  public function groups(): array {
    return $this->groups;
  }

  /**
   * Returns the ids of every group, in drafting order.
   *
   * @return string[]
   *   The group ids.
   */
  public function groupIds(): array {
    return array_column($this->groups, 'groupId');
  }

  /**
   * Returns the definition of a group, or NULL for an unknown id.
   */
  public function group(string $groupId): ?array {
    foreach ($this->groups as $group) {
      if ($group['groupId'] === $groupId) {
        return $group;
      }
    }
    return NULL;
  }

  /**
   * Stores the drafted values of one group.
   */
  public function add(string $groupId, array $fields): void {
    $this->results[$groupId] = $fields;
  }

  /**
   * Returns the drafted main fields, or NULL before they are drafted.
   */
  public function mainFields(): ?array {
    return $this->results['main_fields'] ?? NULL;
  }

  /**
   * Returns the ids of the groups not drafted yet, in drafting order.
   *
   * @return string[]
   *   The pending group ids.
   */
  public function pending(): array {
    return array_values(array_diff($this->groupIds(), array_keys($this->results)));
  }

  /**
   * Returns the versioned draft once every group is drafted, else NULL.
   *
   * The draft is versioned once; later calls return the same draft.
   */
  public function draft(): ?array {
    if ($this->draft === NULL && $this->pending() === []) {
      $this->draft = ($this->versionDraft)($this->consolidate());
    }
    return $this->draft;
  }

  /**
   * Merges the group results into one field map.
   */
  private function consolidate(): array {
    $fields = [];
    foreach ($this->groups as $group) {
      $result = $this->results[$group['groupId']] ?? NULL;
      if ($result === NULL) {
        continue;
      }
      if ($group['groupId'] === 'main_fields') {
        $fields = array_merge($fields, array_intersect_key($result, array_flip($group['fieldNames'])));
        continue;
      }
      foreach ($group['fieldNames'] as $fieldName) {
        if (array_key_exists($fieldName, $result)) {
          $fields[$fieldName] = $result[$fieldName];
        }
        elseif (array_is_list($result)) {
          $fields[$fieldName] = $result;
        }
      }
    }
    return $fields;
  }

}
