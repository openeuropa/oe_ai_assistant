<?php

declare(strict_types=1);

namespace Drupal\schema_builder\Context;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Says which field is being described, and what Drupal asks of it.
 */
class FieldContext {

  /**
   * Class constructor.
   *
   * @param string $entityTypeId
   *   The entity type the field belongs to.
   * @param string $bundle
   *   The bundle the field belongs to.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field being described.
   * @param array $constraints
   *   Constraint options keyed by constraint name, belonging to the level
   *   this context describes rather than to the field throughout.
   * @param array $parameters
   *   What the caller of SchemaBuilder::get() passed in.
   */
  public function __construct(
    public readonly string $entityTypeId,
    public readonly string $bundle,
    public readonly FieldDefinitionInterface $fieldDefinition,
    public readonly array $constraints = [],
    public readonly array $parameters = [],
  ) {}

  /**
   * Returns the options of one constraint, or NULL when it is not set.
   */
  public function getConstraint(string $name): ?array {
    return $this->constraints[$name] ?? NULL;
  }

}
