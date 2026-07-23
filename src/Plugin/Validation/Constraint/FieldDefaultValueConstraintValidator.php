<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\Validation\Constraint;

use Drupal\Core\Config\Schema\TypeResolver;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates default values against the real field definition.
 */
class FieldDefaultValueConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly TypedDataManagerInterface $typedDataManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof FieldDefaultValueConstraint) {
      throw new UnexpectedTypeException($constraint, FieldDefaultValueConstraint::class);
    }

    $object = $this->context->getObject();
    $field_name = $object->getName();

    $entity_type_id = TypeResolver::resolveDynamicTypeName($constraint->entityTypeId, $object);
    $bundle = TypeResolver::resolveDynamicTypeName($constraint->bundle, $object);

    $field_definition = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle)[$field_name] ?? NULL;

    if (!$field_definition) {
      $this->context->buildViolation($constraint->missingFieldMessage)
        ->setParameter('@field', (string) $field_name)
        ->setParameter('@entityTypeId', $entity_type_id)
        ->setParameter('@bundle', $bundle)
        ->addViolation();
      return;
    }

    if (!is_array($value) || !array_key_exists('default_value', $value)) {
      return;
    }

    $default_value = $value['default_value'];
    if (!is_array($default_value) || empty($default_value)) {
      $this->context->addViolation("Field '$field_name' default_value is empty.");
      return;
    }

    try {
      $field = $this->typedDataManager->create($field_definition, $default_value);
      $violations = $field->validate();

      foreach ($violations as $violation) {
        $this->context->buildViolation($constraint->message)
          ->setParameter('@field_name', $field_name)
          ->setParameter('@reason', (string) $violation->getMessage())
          ->atPath('default_value.' . $violation->getPropertyPath())
          ->addViolation();
      }
    }
    catch (\Throwable $e) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@field_name', $field_name)
        ->setParameter('@reason', $e->getMessage())
        ->atPath('default_value')
        ->addViolation();
    }
  }

}
