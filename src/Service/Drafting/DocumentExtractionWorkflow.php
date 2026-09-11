<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\media\MediaInterface;

/**
 * Workflow reader backed by the state field map.
 */
final class DocumentExtractionWorkflow implements DocumentExtractionWorkflowInterface {

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
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

}
