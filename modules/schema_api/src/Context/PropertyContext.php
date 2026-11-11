<?php

declare(strict_types=1);

namespace Drupal\schema_api\Context;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Says which property of which field is being described.
 */
final class PropertyContext extends FieldContext {

  /**
   * Class constructor.
   *
   * @param string $entityTypeId
   *   The entity type the field belongs to.
   * @param string $bundle
   *   The bundle the field belongs to.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field the property belongs to.
   * @param string $propertyName
   *   The property name, such as "value" or "target_id".
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $propertyDefinition
   *   The property being described.
   * @param array $constraints
   *   Constraint options keyed by constraint name, from the property
   *   definition and from the ComplexData constraints of the field item.
   * @param array $parameters
   *   What the caller of SchemaApi::get() passed in.
   */
  public function __construct(
    string $entityTypeId,
    string $bundle,
    FieldDefinitionInterface $fieldDefinition,
    public readonly string $propertyName,
    public readonly DataDefinitionInterface $propertyDefinition,
    array $constraints,
    array $parameters = [],
  ) {
    parent::__construct($entityTypeId, $bundle, $fieldDefinition, $constraints, $parameters);
  }

}
