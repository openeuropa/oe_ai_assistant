<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Resolves a text format for formatted-text items the LLM left unset.
 *
 * EntityJsonSchemaComposer's JSON Schema omits the `format` property from
 * formatted-text fields, so LLM output never carries one. Deserializing that
 * output leaves the item's `format` property empty, which Drupal's render
 * pipeline falls back to `plain_text` for, escaping markup the LLM produced
 * (e.g. a literal &lt;p&gt; on the page) instead of rendering it.
 *
 * Used by both DraftEntityBuilder (on the built entity) and
 * InlineEntityHydrator (on every inline child it builds, including nested
 * ones) after deserialization, since neither can rely on the other to have
 * covered the entities it builds.
 */
class TextFormatResolver {

  public function __construct(
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Fills in the format of any unset formatted-text item on the entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to resolve formats on. Mutated in place.
   */
  public function resolveEntityFormats(FieldableEntityInterface $entity): void {
    foreach ($entity->getFields() as $items) {
      $fieldDefinition = $items->getFieldDefinition();
      if (!$fieldDefinition->getFieldStorageDefinition()->getPropertyDefinition('format')) {
        continue;
      }
      foreach ($items as $item) {
        if (empty($item->format)) {
          $item->format = $this->resolveFormat((array) $fieldDefinition->getSetting('allowed_formats'));
        }
      }
    }
  }

  /**
   * Resolves a format ID the current user is permitted to use.
   *
   * @param array $allowedFormats
   *   The field's `allowed_formats` setting; empty means unrestricted.
   *
   * @return string
   *   A format ID the current user may use.
   */
  private function resolveFormat(array $allowedFormats): string {
    if ($allowedFormats) {
      $permitted = array_keys(filter_formats($this->currentUser));
      $intersection = array_intersect($allowedFormats, $permitted);
      if ($intersection) {
        return reset($intersection);
      }
    }
    return filter_default_format($this->currentUser);
  }

}
