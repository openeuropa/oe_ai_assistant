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

    if (!is_string($constraint->field)) {
      throw new UnexpectedTypeException($constraint->field, 'string');
    }

    $object = $this->context->getObject();
    $field_name = TypeResolver::resolveDynamicTypeName($constraint->field, $object);
    if (empty($field_name)) {
      $this->context->addViolation("Field name is empty.");
      return;
    }

    $entity_type_id = TypeResolver::resolveDynamicTypeName($constraint->entityTypeId, $object);
    $bundle = TypeResolver::resolveDynamicTypeName($constraint->bundle, $object);

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
