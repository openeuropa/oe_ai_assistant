<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the drafts stored on the conversation of an editorial session.
 */
final class DraftHistory implements DraftHistoryInterface {

  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function nextVersion(EntityInterface $session, ?int $revisionOf = NULL): array {
    $drafts = $this->collectDrafts($session);
    $root = $revisionOf === NULL ? NULL : self::find($drafts, $revisionOf);
    if ($root === NULL) {
      $major = max([0, ...array_column($drafts, 'major')]) + 1;
      $minor = 0;
    }
    else {
      $major = (int) $root['major'];
      $siblings = array_filter($drafts, fn (array $draft): bool => (int) $draft['major'] === $major);
      $minor = max(array_column($siblings, 'minor')) + 1;
    }
    return [
      'version' => count($drafts) + 1,
      'major' => $major,
      'minor' => $minor,
      // Only a draft that joined a group revises another one: a root that
      // is not stored opens a new group instead.
      'revisionOf' => $root === NULL ? NULL : $revisionOf,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function listDrafts(EntityInterface $session): array {
    $drafts = $this->collectDrafts($session);
    // Revisions follow the draft they belong to, whatever else was drafted
    // in between.
    usort($drafts, fn (array $a, array $b): int => [$a['major'], $a['minor']] <=> [$b['major'], $b['minor']]);

    $entries = [];
    foreach ($drafts as $draft) {
      // The schemas of the groups are only needed to revise a draft, so the
      // listing names them instead of carrying them.
      $context = $draft['context'] ?? [];
      $groups = is_array($context['groups'] ?? NULL) ? $context['groups'] : [];
      unset($context['groups']);

      $entries[] = [
        'name' => self::nameOf($draft),
        'label' => self::labelOf($draft),
        'version' => (int) $draft['version'],
        'revisionOf' => $draft['revisionOf'] ?? NULL,
        'groups' => array_map(
          fn (array $group): array => ['id' => $group['groupId'], 'label' => $group['label']],
          $groups,
        ),
        'context' => $context,
      ];
    }
    return $entries;
  }

  /**
   * {@inheritdoc}
   */
  public function getDraftContent(EntityInterface $session, int $version): ?array {
    $draft = self::find($this->collectDrafts($session), $version);
    if ($draft === NULL) {
      return NULL;
    }
    return [
      'name' => self::nameOf($draft),
      'fields' => $draft['fields'] ?? [],
      'templateId' => $draft['context']['template']['id'] ?? NULL,
      'context' => $draft['context'] ?? NULL,
    ];
  }

  /**
   * Returns the grouped number of a draft, such as "2.1".
   */
  private static function labelOf(array $draft): string {
    return $draft['major'] . '.' . $draft['minor'];
  }

  /**
   * Returns the name the editor and the model see for a draft.
   */
  private static function nameOf(array $draft): string {
    return 'Draft ' . self::labelOf($draft);
  }

  /**
   * Returns the draft carrying a version, or NULL when none does.
   */
  private static function find(array $drafts, int $version): ?array {
    foreach ($drafts as $draft) {
      if ((int) $draft['version'] === $version) {
        return $draft;
      }
    }
    return NULL;
  }

  /**
   * Collects every stored draft in transcript order.
   *
   * Any tool call whose result carries a draft counts, whichever tool
   * produced it.
   *
   * @return array
   *   The drafts shaped {version, major, minor, context, fields, revisionOf}.
   */
  private function collectDrafts(EntityInterface $session): array {
    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    $drafts = [];
    foreach ($storage->loadTranscript($session) as $message) {
      foreach ($message->getToolCalls() as $call) {
        if (isset($call['result']['draft'])) {
          $drafts[] = $call['result']['draft'];
        }
      }
    }
    return $drafts;
  }

}
