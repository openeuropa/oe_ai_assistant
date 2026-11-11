<?php

declare(strict_types=1);

namespace Drupal\schema_builder\Attribute;

use Drupal\schema_builder\Context\FieldContext;

/**
 * Says which fields a builder runs on, and when.
 *
 * Every property left out matches anything, so a builder that declares only
 * a field type runs on that field type wherever it appears.
 */
abstract class BuilderBase {

  /**
   * Class constructor.
   *
   * @param string|null $entity_type
   *   The entity type to match, or NULL for any.
   * @param string|null $bundle
   *   The bundle to match, or NULL for any.
   * @param string|null $field_name
   *   The field machine name to match, or NULL for any.
   * @param string|array|null $field_type
   *   One field type or a list of them, or NULL for any.
   * @param string|array|null $constraint
   *   One constraint name or a list of them, or NULL for any. The builder
   *   runs when the level carries any one of them, which spares it from
   *   opening with a check for the constraint it reads.
   * @param float $weight
   *   Builders run from the lightest to the heaviest, so the heaviest has
   *   the last word on a keyword two of them set. A builder that matches one
   *   bundle therefore outweighs one that matches every field.
   */
  public function __construct(
    public readonly ?string $entity_type = NULL,
    public readonly ?string $bundle = NULL,
    public readonly ?string $field_name = NULL,
    public readonly string|array|null $field_type = NULL,
    public readonly string|array|null $constraint = NULL,
    public readonly float $weight = 0,
  ) {}

  /**
   * Checks whether the builder runs on the field in the context.
   */
  public function matches(FieldContext $context): bool {
    return $this->accepts($this->entity_type, $context->entityTypeId)
      && $this->accepts($this->bundle, $context->bundle)
      && $this->accepts($this->field_name, $context->fieldDefinition->getName())
      && $this->accepts($this->field_type, $context->fieldDefinition->getType())
      && $this->carriesConstraint($context);
  }

  /**
   * Checks whether the level carries one of the declared constraints.
   */
  protected function carriesConstraint(FieldContext $context): bool {
    if ($this->constraint === NULL) {
      return TRUE;
    }

    return array_intersect((array) $this->constraint, array_keys($context->constraints)) !== [];
  }

  /**
   * Checks one declared value against the one the context carries.
   */
  protected function accepts(string|array|null $declared, string $actual): bool {
    return $declared === NULL || in_array($actual, (array) $declared, TRUE);
  }

}
