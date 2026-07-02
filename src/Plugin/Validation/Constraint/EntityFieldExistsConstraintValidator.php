<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\Validation\Constraint;

use Drupal\Core\Config\Schema\TypeResolver;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates that a field exists in a bundle.
 */
class EntityFieldExistsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof EntityFieldExistsConstraint) {
      throw new UnexpectedTypeException($constraint, EntityFieldExistsConstraint::class);
    }
    $field_name = NULL;

    $field_name = is_string($value) ? $value : $this->context->getObject()->getName();

    if ($field_name === NULL || $field_name === '') {
      return;
    }

    $entity_type_id = TypeResolver::resolveDynamicTypeName("[$constraint->entityTypeId]", $this->context->getObject());
    $bundle = TypeResolver::resolveDynamicTypeName("[$constraint->bundle]", $this->context->getObject());

    $definitions = $this->entityFieldManager
      ->getFieldDefinitions($entity_type_id, $bundle);

    if (!isset($definitions[$field_name])) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@field', (string) $field_name)
        ->setParameter('@entityTypeId', $entity_type_id)
        ->setParameter('@bundle', $bundle)
        ->addViolation();
    }
  }

}
