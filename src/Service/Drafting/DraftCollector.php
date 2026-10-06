<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

/**
 * Gathers the group results of one drafting turn.
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
   * DraftCollector constructor.
   *
   * @param array $groups
   *   The schema groups, each with groupId, label, fieldNames and
   *   schemaSlice, in drafting order.
   */
  public function __construct(
    private readonly array $groups,
  ) {}

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
   * Fills every group but the named ones from an existing draft.
   *
   * What stays pending is then exactly the groups being revised, so the
   * draft is versioned once they have been drafted again.
   *
   * @param array $fields
   *   The field values of the draft being revised, keyed by field name.
   * @param string[] $revised
   *   The ids of the groups that will be drafted again.
   */
  public function seedFrom(array $fields, array $revised): void {
    foreach ($this->groups as $group) {
      if (in_array($group['groupId'], $revised, TRUE)) {
        continue;
      }
      $this->results[$group['groupId']] = self::slice($fields, $group);
    }
  }

  /**
   * Returns the values a group holds in an existing draft.
   *
   * @param string $groupId
   *   The id of the group to read.
   * @param array $fields
   *   The field values of that draft, keyed by field name.
   */
  public function valuesOf(string $groupId, array $fields): array {
    $group = $this->group($groupId);
    return $group === NULL ? [] : self::slice($fields, $group);
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
   * Whether every group has been drafted, so the draft can be versioned.
   */
  public function complete(): bool {
    return $this->pending() === [];
  }

  /**
   * Merges what has been drafted so far into one field map.
   *
   * @return array
   *   The field values, keyed by field name.
   */
  public function fields(): array {
    $fields = [];
    foreach ($this->groups as $group) {
      $result = $this->results[$group['groupId']] ?? NULL;
      if ($result === NULL) {
        continue;
      }
      if ($group['groupId'] === 'main_fields') {
        $fields = array_merge($fields, self::slice($result, $group));
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

  /**
   * Keeps the values of a group's own fields.
   */
  private static function slice(array $fields, array $group): array {
    return array_intersect_key($fields, array_flip($group['fieldNames']));
  }

}
