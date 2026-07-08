<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a field exists on a bundle for a content entity type.
 */
#[Constraint(
  id: 'EntityFieldExists',
  label: new TranslatableMarkup('Entity field exists', [], ['context' => 'Validation']),
  type: 'mapping'
)]
class EntityFieldExistsConstraint extends SymfonyConstraint {

  /**
   * The error message if validation fails.
   *
   * @var string
   */
  public $message = "The field '@field' does not exist on the '@bundle' bundle of '@entityTypeId' entity type.";

  /**
   * The bundle that should contain the validated field.
   *
   * This can contain variable values (e.g., `%parent`) that will be replaced.
   *
   * @var string
   *
   * @see \Drupal\Core\Config\Schema\TypeResolver::replaceVariable()
   */
  public string $bundle;

  /**
   * The entity type ID that should contain the validated field.
   *
   * This can contain variable values (e.g., `%parent`) that will be replaced.
   *
   * @var string
   *
   * @see \Drupal\Core\Config\Schema\TypeResolver::replaceVariable()
   */
  public string $entityTypeId;

  /**
   * The field name to validate.
   *
   * This can contain variable values (e.g., `%parent`) that will be replaced.
   *
   * @var string
   *
   * @see \Drupal\Core\Config\Schema\TypeResolver::replaceVariable()
   */
  public $field;

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return [
      'entityTypeId',
      'bundle',
      'field',
    ];
  }

}
