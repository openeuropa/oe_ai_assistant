<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a reference item matches the parent field target type.
 */
#[Constraint(
  id: 'ReferenceTargetType',
  label: new TranslatableMarkup('AI drafting template reference target type', [], ['context' => 'Validation']),
  type: ['mapping'],
)]
final class ReferenceTargetTypeConstraint extends SymfonyConstraint {

  /**
   * Source entity type ID.
   */
  public string $sourceEntityTypeId;

  /**
   * Source bundle.
   */
  public string $sourceBundle;

  /**
   * Entity type does not match the field target type.
   */
  public string $message = "Item @path: entity_type '@entity_type' does not match field target type '@target_type'.";

  /**
   * Template field type does not match the reference field type.
   */
  public string $invalidFieldTypeMessage = "Field '@field' is a '@actual' field, not '@expected'.";

  /**
   * Reference field definition is missing the field type.
   */
  public string $missingFieldTypeMessage = "Reference field '@field' must declare type '@type'.";

  /**
   * Bundle is not allowed by the field.
   */
  public string $disallowedBundleMessage = "bundle '@bundle' is not allowed in field '@field'.";

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return [
      'sourceEntityTypeId',
      'sourceBundle',
    ];
  }

}
