<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;

/**
 * Workflow reader backed by the state field map.
 */
final class DocumentExtractionWorkflow implements DocumentExtractionWorkflowInterface {

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getBundles(): array {
    $map = $this->entityFieldManager->getFieldMap()['media'][self::STATE_FIELD]['bundles'] ?? [];

    return array_values($map);
  }

  /**
   * {@inheritdoc}
   */
  public function appliesTo(MediaInterface $media): bool {
    return in_array($media->bundle(), $this->getBundles(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getState(MediaInterface $media): string {
    $state = $media->hasField(self::STATE_FIELD)
      ? (string) ($media->get(self::STATE_FIELD)->value ?? '')
      : '';

    return $state === '' ? self::STATE_SCHEDULED : $state;
  }

  /**
   * {@inheritdoc}
   */
  public function isSettled(string $state): bool {
    return $state === self::STATE_DONE || $state === self::STATE_ERROR;
  }

  /**
   * {@inheritdoc}
   */
  public function findPending(int $limit, int $staleAfterSeconds): array {
    $pending = [];
    foreach ($this->findInStates([self::STATE_SCHEDULED, self::STATE_EXTRACTED], $limit) as $id) {
      $pending[$id] = FALSE;
    }
    $remaining = $limit - count($pending);
    if ($remaining <= 0) {
      return $pending;
    }

    $threshold = $this->time->getRequestTime() - $staleAfterSeconds;
    foreach ($this->findInStates([self::STATE_EXTRACTING, self::STATE_SUMMARIZING], $remaining, $threshold) as $id) {
      $pending[$id] = TRUE;
    }

    return $pending;
  }

  /**
   * Returns the ids of tracked documents in the given states, oldest first.
   *
   * @param string[] $states
   *   The states to match.
   * @param int $limit
   *   Maximum number of ids.
   * @param int|null $changedBefore
   *   When set, only documents last changed before this timestamp.
   *
   * @return int[]
   *   The media ids.
   */
  private function findInStates(array $states, int $limit, ?int $changedBefore = NULL): array {
    $bundles = $this->getBundles();
    if ($bundles === []) {
      return [];
    }
    $query = $this->entityTypeManager->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $bundles, 'IN')
      ->condition(self::STATE_FIELD, $states, 'IN')
      ->sort('mid')
      ->range(0, $limit);
    if ($changedBefore !== NULL) {
      $query->condition('changed', $changedBefore, '<');
    }

    return array_map('intval', array_values($query->execute()));
  }

}
