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
 * Validates reference item entity_type against the parent field target type.
 */
final class ReferenceTargetTypeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ReferenceTargetTypeConstraint) {
      throw new UnexpectedTypeException($constraint, ReferenceTargetTypeConstraint::class);
    }

    if (!is_array($value)) {
      return;
    }

    $object = $this->context->getObject();

    $source_entity_type_id = TypeResolver::resolveDynamicTypeName($constraint->sourceEntityTypeId, $object);
    $source_bundle = TypeResolver::resolveDynamicTypeName($constraint->sourceBundle, $object);
    $source_field_name = $object->getName();

    if ($source_entity_type_id === '' || $source_bundle === '' || $source_field_name === '') {
      return;
    }

    $field_definition = $this->entityFieldManager
      ->getFieldDefinitions($source_entity_type_id, $source_bundle)[$source_field_name] ?? NULL;

    if ($field_definition === NULL) {
      return;
    }

    $field_type = $field_definition->getType();
    if (!in_array($field_type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      return;
    }

    $schema_type = $value['type'] ?? NULL;
    if ($schema_type === NULL || $schema_type === '') {
      $this->context->buildViolation($constraint->missingFieldTypeMessage)
        ->setParameter('@field', $source_field_name)
        ->setParameter('@type', $field_type)
        ->addViolation();

      return;
    }

    if ($schema_type !== $field_type) {
      $this->context->buildViolation($constraint->invalidFieldTypeMessage)
        ->setParameter('@field', $source_field_name)
        ->setParameter('@actual', $field_type)
        ->setParameter('@expected', (string) $schema_type)
        ->addViolation();

      return;
    }

    $target_type = $field_definition->getSetting('target_type');
    if (!$target_type || empty($value['items']) || !is_array($value['items'])) {
      return;
    }

    $handler_settings = $field_definition->getSetting('handler_settings') ?? [];
    $target_bundles = $handler_settings['target_bundles'] ?? NULL;

    foreach ($value['items'] as $delta => $item) {
      if (!is_array($item)) {
        continue;
      }

      $entity_type = $item['entity_type'] ?? NULL;

      if ($entity_type !== NULL && $entity_type !== '' && $entity_type !== $target_type) {
        $path = "items.$delta.entity_type";

        $this->context->buildViolation($constraint->message)
          ->setParameter('@path', $path)
          ->setParameter('@entity_type', (string) $entity_type)
          ->setParameter('@target_type', (string) $target_type)
          ->atPath($path)
          ->addViolation();
      }

      $bundle = $item['bundle'] ?? NULL;

      if (
        $bundle !== NULL &&
        $bundle !== '' &&
        is_array($target_bundles) &&
        $target_bundles &&
        !in_array($bundle, $target_bundles, TRUE)
      ) {
        $path = "items.$delta.bundle";

        $this->context->buildViolation($constraint->disallowedBundleMessage)
          ->setParameter('@bundle', (string) $bundle)
          ->setParameter('@field', $source_field_name)
          ->atPath($path)
          ->addViolation();
      }
    }
  }

}
