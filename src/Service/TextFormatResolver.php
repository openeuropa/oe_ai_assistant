<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Resolves text formats against the formats the current user may use.
 */
final class TextFormatResolver implements TextFormatResolverInterface {

  /**
   * The ids of the formats the current user may use, by weight.
   *
   * @var string[]|null
   */
  private ?array $usable = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolveEntityFormats(FieldableEntityInterface $entity): void {
    foreach ($entity->getFields() as $items) {
      if (!$items->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinition('format')) {
        continue;
      }
      foreach ($items as $item) {
        $format = $item->get('format')->getValue();
        if ($format !== NULL && $format !== '' && in_array($format, $this->usableFormats(), TRUE)) {
          continue;
        }
        $item->set('format', $this->usableFormats()[0]);
      }
    }
  }

  /**
   * Returns the ids of the enabled formats the current user may use.
   *
   * Ordered by weight, so the first entry is the user's default format. The
   * fallback format is usable by everyone, so the list is never empty.
   *
   * @return string[]
   *   The format ids.
   */
  private function usableFormats(): array {
    if ($this->usable === NULL) {
      $formats = $this->entityTypeManager->getStorage('filter_format')->loadByProperties(['status' => TRUE]);
      uasort($formats, static fn ($a, $b): int => $a->get('weight') <=> $b->get('weight'));
      $this->usable = array_keys(array_filter(
        $formats,
        fn ($format): bool => $format->access('use', $this->currentUser),
      ));
    }
    return $this->usable;
  }

}
