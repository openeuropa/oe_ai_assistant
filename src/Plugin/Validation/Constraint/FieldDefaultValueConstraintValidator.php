<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\Validation\Constraint;

use Drupal\Core\Config\Schema\TypeResolver;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
      $default_value = $this->resolveEntityReferenceDefaultValue(
        $default_value, $field_definition, $entity_type_id, $bundle,
      );

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

  /**
   * Resolves target_uuid entries to target_id via core's own mechanism.
   *
   * Reuses core's field-config-default resolution path:
   * FieldConfigBase::getDefaultValue() calls
   * $itemClass::processDefaultValue($default_value, $entity, $definition);
   * EntityReferenceItem's override converts target_uuid to target_id via
   * entity.repository, dropping entries whose UUID does not resolve. A
   * default with no target_uuid entries (including entirely non-reference
   * fields) is returned unchanged without touching the entity type manager.
   *
   * @param array $rawValue
   *   The raw default_value sequence.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The real field definition the default applies to.
   * @param string $entityTypeId
   *   The entity type ID that owns the field.
   * @param string $bundle
   *   The bundle that owns the field.
   *
   * @return array
   *   The default_value sequence with target_uuid resolved to target_id.
   *
   * @throws \RuntimeException
   *   If a target_uuid does not resolve to an existing entity.
   */
  private function resolveEntityReferenceDefaultValue(array $rawValue, FieldDefinitionInterface $fieldDefinition, string $entityTypeId, string $bundle): array {
    $hasUuidReference = FALSE;
    foreach ($rawValue as $item) {
      if (is_array($item) && array_key_exists('target_uuid', $item)) {
        $hasUuidReference = TRUE;
        break;
      }
    }
    if (!$hasUuidReference) {
      return $rawValue;
    }

    $bundleKey = $this->entityTypeManager->getDefinition($entityTypeId)->getKey('bundle');
    $scratch = $bundleKey
      ? $this->entityTypeManager->getStorage($entityTypeId)->create([$bundleKey => $bundle])
      : $this->entityTypeManager->getStorage($entityTypeId)->create();

    $fieldItemListClass = $fieldDefinition->getClass();
    $resolved = $fieldItemListClass::processDefaultValue($rawValue, $scratch, $fieldDefinition);

    if (count($resolved) < count($rawValue)) {
      throw new \RuntimeException(sprintf(
        "One or more target_uuid values for field '%s' do not reference an existing entity.",
        $fieldDefinition->getName(),
      ));
    }

    return $resolved;
  }

}
