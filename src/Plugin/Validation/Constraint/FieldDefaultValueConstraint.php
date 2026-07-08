<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a field default value is valid.
 */
#[Constraint(
  id: 'FieldDefaultValue',
  label: new TranslatableMarkup('Field default value is valid', [], ['context' => 'Validation']),
  type: 'sequence'
)]
class FieldDefaultValueConstraint extends SymfonyConstraint {

  /**
   * The bundle that contains the field receiving the default value.
   *
   * This can contain variable values (e.g., `%parent`) that will be replaced.
   *
   * @var string
   *
   * @see \Drupal\Core\Config\Schema\TypeResolver::replaceVariable()
   */
  public string $bundle;

  /**
   * The entity type ID that contains the field receiving the default value.
   *
   * This can contain variable values (e.g., `%parent`) that will be replaced.
   *
   * @var string
   *
   * @see \Drupal\Core\Config\Schema\TypeResolver::replaceVariable()
   */
  public string $entityTypeId;

  /**
   * The error message if validation fails.
   *
   * @var string
   */
  public string $message = "Default value for field '@field_name' is invalid: @reason";

  /**
   * The field does not exist in the bundle.
   *
   * @var string
   */
  public $missingFieldMessage = "The field '@field' does not exist on the '@bundle' bundle of '@entityTypeId' entity type.";

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return [
      'entityTypeId',
      'bundle',
    ];
  }

}
