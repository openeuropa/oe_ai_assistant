<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\filter\FilterFormatInterface;
use Drupal\filter\FilterFormatRepositoryInterface;

/**
 * Gives every formatted-text item a format its field and user allow.
 *
 * The allowed formats are the enabled formats the field allows that the
 * current user may use; when the user may use none of them, the field's
 * formats regardless of permissions. An item keeps its format when it is
 * allowed. Otherwise it takes the stored entity's format at the same delta
 * when that is allowed, or else the first allowed format in weight order that
 * renders HTML, since drafted text is HTML, or else the first allowed one. A
 * field whose allowed formats are all disabled or gone has its items' formats
 * cleared, so the text renders as escaped plain text.
 */
class TextFormatResolver {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly FilterFormatRepositoryInterface $filterFormatRepository,
  ) {}

  /**
   * Sets an allowed format on every formatted-text item of the entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to resolve formats on. Mutated in place.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $stored
   *   The stored entity the values replace, if any.
   */
  public function resolveEntityFormats(FieldableEntityInterface $entity, ?FieldableEntityInterface $stored = NULL): void {
    foreach ($entity->getFields() as $items) {
      $fieldDefinition = $items->getFieldDefinition();
      if (!$this->isFormatted($fieldDefinition)) {
        continue;
      }
      $formats = $this->formatsFor($fieldDefinition);
      $default = array_key_first($this->preferredFormats($formats));
      foreach ($items as $delta => $item) {
        if ($item->format !== NULL && isset($formats[$item->format])) {
          continue;
        }
        $storedFormat = $stored?->get($items->getName())->get($delta)?->format;
        $item->format = $storedFormat !== NULL && isset($formats[$storedFormat])
          ? $storedFormat
          : ($default === NULL ? NULL : (string) $default);
      }
    }
  }

  /**
   * Checks whether a field holds formatted text.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field.
   *
   * @return bool
   *   TRUE when the field's items carry a text format.
   */
  public function isFormatted(FieldDefinitionInterface $fieldDefinition): bool {
    return $fieldDefinition->getFieldStorageDefinition()->getPropertyDefinition('format')?->getDataType() === 'filter_format';
  }

  /**
   * Returns the formats a formatted-text field's items may take.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The formatted-text field.
   *
   * @return \Drupal\filter\FilterFormatInterface[]
   *   The allowed formats, keyed by ID, in weight order.
   */
  public function formatsFor(FieldDefinitionInterface $fieldDefinition): array {
    $formats = $this->filterFormatRepository->getAllFormats();
    $allowedFormats = array_filter((array) $fieldDefinition->getSetting('allowed_formats'));
    if ($allowedFormats) {
      $formats = array_intersect_key($formats, array_flip($allowedFormats));
    }
    return array_intersect_key($formats, $this->filterFormatRepository->getFormatsForAccount($this->currentUser)) ?: $formats;
  }

  /**
   * Returns the HTML tags a format allows.
   *
   * @param \Drupal\filter\FilterFormatInterface $format
   *   The format.
   *
   * @return string[]|null
   *   The allowed tag names, empty for a format such as plain text that
   *   allows none, or NULL when the format allows any tag it does not forbid.
   *
   * @see ::forbiddenTags()
   */
  public function allowedTags(FilterFormatInterface $format): ?array {
    $restrictions = $format->getHtmlRestrictions();
    if (!is_array($restrictions) || !isset($restrictions['allowed'])) {
      return NULL;
    }
    // The '*' key holds the attributes allowed on every tag, not a tag.
    return array_values(array_diff(array_map('strval', array_keys($restrictions['allowed'])), ['*']));
  }

  /**
   * Returns the HTML tags a format forbids.
   *
   * @param \Drupal\filter\FilterFormatInterface $format
   *   The format.
   *
   * @return string[]
   *   The forbidden tag names, empty when the format forbids none.
   */
  public function forbiddenTags(FilterFormatInterface $format): array {
    $restrictions = $format->getHtmlRestrictions();
    return is_array($restrictions) ? $restrictions['forbidden_tags'] ?? [] : [];
  }

  /**
   * Narrows formats to those that render HTML, since drafted text is HTML.
   *
   * @param \Drupal\filter\FilterFormatInterface[] $formats
   *   The formats, keyed by ID.
   *
   * @return \Drupal\filter\FilterFormatInterface[]
   *   The formats that allow an HTML tag, or all of them when none does.
   */
  public function preferredFormats(array $formats): array {
    return array_filter($formats, fn (FilterFormatInterface $format): bool => $this->allowedTags($format) !== []) ?: $formats;
  }

}
