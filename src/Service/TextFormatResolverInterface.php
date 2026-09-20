<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Gives every formatted text item of a built entity a format that renders.
 */
interface TextFormatResolverInterface {

  /**
   * Replaces missing or unusable text formats on the entity's items.
   *
   * A format stays when it exists, is enabled and the current user may use
   * it. Any other value becomes the current user's default format. Drupal
   * renders text with an unknown format as an empty string, so a format the
   * model invented would otherwise blank the field in preview and on save.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to fix in place.
   */
  public function resolveEntityFormats(FieldableEntityInterface $entity): void;

}
