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
  public function countDrafts(EntityInterface $session): int {
    return count($this->collectDrafts($session));
  }

  /**
   * {@inheritdoc}
   */
  public function listDrafts(EntityInterface $session): array {
    $entries = [];
    $majors = [];
    $minors = [];

    foreach ($this->collectDrafts($session) as $draft) {
      $version = (int) $draft['version'];
      $root = isset($draft['revisionOf']) ? (int) $draft['revisionOf'] : NULL;
      // A revision joins the group of the draft it started from. Anything
      // else, a new draft or a revision of a draft that is no longer
      // stored, opens a group of its own.
      if ($root !== NULL && isset($majors[$root])) {
        $major = $majors[$root];
        $minor = ++$minors[$root];
      }
      else {
        $root = NULL;
        $major = $majors[$version] = count($majors) + 1;
        $minor = $minors[$version] = 0;
      }
      // The schemas of the groups are only needed to revise a draft, so the
      // listing names them instead of carrying them.
      $context = $draft['context'] ?? [];
      $groups = is_array($context['groups'] ?? NULL) ? $context['groups'] : [];
      unset($context['groups']);

      $label = $major . '.' . $minor;
      $entries[] = [
        'major' => $major,
        'minor' => $minor,
        'draft' => [
          'name' => 'Draft ' . $label,
          'label' => $label,
          'version' => $version,
          'revisionOf' => $root,
          'groups' => array_map(
            fn (array $group): array => ['id' => $group['groupId'], 'label' => $group['label']],
            $groups,
          ),
          'context' => $context,
        ],
      ];
    }

    // Revisions follow the draft they belong to, whatever else was drafted
    // in between.
    usort($entries, fn (array $a, array $b): int => [$a['major'], $a['minor']] <=> [$b['major'], $b['minor']]);
    return array_column($entries, 'draft');
  }

  /**
   * {@inheritdoc}
   */
  public function getDraftContent(EntityInterface $session, int $version): ?array {
    foreach ($this->collectDrafts($session) as $draft) {
      if ((int) $draft['version'] !== $version) {
        continue;
      }
      return [
        'fields' => $draft['fields'] ?? [],
        'templateId' => $draft['context']['template']['id'] ?? NULL,
        'context' => $draft['context'] ?? NULL,
      ];
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
   *   The drafts shaped {version, context, fields, revisionOf}.
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
