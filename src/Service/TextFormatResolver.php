<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\filter\FilterFormatInterface;
use Drupal\filter\FilterFormatRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives every formatted-text item a format its field is configured for.
 *
 * The candidates are the enabled formats the field allows that the current
 * user may use; when the user may use none of them, the field's formats
 * regardless of permissions. An item keeps its format when it is a
 * candidate. Otherwise it takes the stored entity's format at the same delta
 * when that is a candidate, or else the first candidate in weight order that
 * renders HTML, since drafted text is HTML, or the first candidate.
 */
class TextFormatResolver {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly FilterFormatRepositoryInterface $filterFormatRepository,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Sets a valid format on the formatted-text items of the given fields.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to resolve formats on. Mutated in place.
   * @param string[] $fieldNames
   *   The fields to resolve. Fields the entity lacks are skipped, and fields
   *   not named keep their values, defaults included.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $current
   *   The stored entity the values replace, if any. Its format at the same
   *   delta stands in for an item's missing or invalid one when it is a
   *   candidate.
   */
  public function resolveEntityFormats(FieldableEntityInterface $entity, array $fieldNames, ?FieldableEntityInterface $current = NULL): void {
    foreach ($fieldNames as $fieldName) {
      if (!$entity->hasField($fieldName)) {
        continue;
      }
      $items = $entity->get($fieldName);
      $fieldDefinition = $items->getFieldDefinition();
      if (!$this->isFormatted($fieldDefinition)) {
        continue;
      }

      $allowedFormats = array_filter((array) $fieldDefinition->getSetting('allowed_formats'));
      $formats = $this->filterFormatRepository->getAllFormats();
      if ($allowedFormats) {
        $formats = array_intersect_key($formats, array_flip($allowedFormats));
      }
      $candidates = array_intersect_key($formats, $this->filterFormatRepository->getFormatsForAccount($this->currentUser)) ?: $formats;
      $default = NULL;
      foreach ($items as $delta => $item) {
        if ($item->format !== NULL && isset($candidates[$item->format])) {
          continue;
        }
        $stored = $current?->get($fieldName)->get($delta)?->format;
        $item->format = $stored !== NULL && isset($candidates[$stored])
          ? $stored
          : $default ??= $this->defaultFormat($fieldDefinition, $candidates);
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
   * Returns the format a new item of the field gets.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The formatted-text field.
   * @param \Drupal\filter\FilterFormatInterface[] $formats
   *   The candidate formats, keyed by ID, in weight order.
   *
   * @return string
   *   The format ID.
   */
  private function defaultFormat(FieldDefinitionInterface $fieldDefinition, array $formats): string {
    if ($formats === []) {
      $this->logger->error('None of the text formats allowed on the @field field exists or is enabled.', [
        '@field' => $fieldDefinition->getName(),
      ]);
      return $this->filterFormatRepository->getDefaultFormat($this->currentUser)->id();
    }

    foreach ($formats as $id => $format) {
      if ($this->rendersHtml($format)) {
        return (string) $id;
      }
    }
    return (string) array_key_first($formats);
  }

  /**
   * Checks whether a format renders HTML rather than escaping it.
   *
   * @param \Drupal\filter\FilterFormatInterface $format
   *   The format.
   *
   * @return bool
   *   FALSE for a format whose filters allow no tag at all, such as plain
   *   text, TRUE otherwise.
   */
  private function rendersHtml(FilterFormatInterface $format): bool {
    $restrictions = $format->getHtmlRestrictions();
    return !is_array($restrictions) || ($restrictions['allowed'] ?? NULL) !== [];
  }

}
