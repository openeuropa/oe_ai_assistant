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
 * An item keeps its format when the format exists, is enabled and the field
 * allows it. Any other format is replaced by the first format in weight
 * order that renders HTML, since drafted text is HTML: one of the field's
 * allowed formats when the field restricts its formats, otherwise one of the
 * current user's formats. Without such a format, the first one is used.
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
   *   delta outranks the item's own while the field is configured for it.
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

      $restricted = array_filter((array) $fieldDefinition->getSetting('allowed_formats'));
      $formats = $this->filterFormatRepository->getAllFormats();
      if ($restricted) {
        $formats = array_intersect_key($formats, array_flip($restricted));
      }
      $default = NULL;
      foreach ($items as $delta => $item) {
        $stored = $current?->get($fieldName)->get($delta)?->format;
        if ($stored !== NULL && isset($formats[$stored])) {
          $item->format = $stored;
        }
        elseif ($item->format === NULL || !isset($formats[$item->format])) {
          $item->format = $default ??= $this->defaultFormat($fieldDefinition, $restricted ? $formats : NULL);
        }
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
   * @param \Drupal\filter\FilterFormatInterface[]|null $allowedFormats
   *   The enabled formats the field allows, in weight order, or NULL when the
   *   field allows any format.
   *
   * @return string
   *   The format ID.
   */
  private function defaultFormat(FieldDefinitionInterface $fieldDefinition, ?array $allowedFormats): string {
    $formats = $allowedFormats ?? $this->filterFormatRepository->getFormatsForAccount($this->currentUser);
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
