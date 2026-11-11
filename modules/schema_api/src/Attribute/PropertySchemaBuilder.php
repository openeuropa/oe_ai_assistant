<?php

declare(strict_types=1);

namespace Drupal\schema_api\Attribute;

use Drupal\schema_api\Context\FieldContext;
use Drupal\schema_api\Context\PropertyContext;

/**
 * Marks a class that builds part of the schema of one property.
 */
#[\Attribute(
  \Attribute::TARGET_CLASS,
)]
final class PropertySchemaBuilder extends SchemaBuilderBase {

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
   * @param string|null $property_name
   *   The property name to match, such as "value", or NULL for any.
   * @param string|array|null $data_type
   *   One typed-data type or a list of them, or NULL for any. Matching a
   *   data type reaches every field type that stores it, which a field type
   *   match cannot.
   * @param string|array|null $constraint
   *   One constraint name or a list of them, or NULL for any.
   * @param float $weight
   *   Builders run from the lightest to the heaviest, so the heaviest has
   *   the last word on a keyword two of them set.
   */
  public function __construct(
    ?string $entity_type = NULL,
    ?string $bundle = NULL,
    ?string $field_name = NULL,
    string|array|null $field_type = NULL,
    public readonly ?string $property_name = NULL,
    public readonly string|array|null $data_type = NULL,
    string|array|null $constraint = NULL,
    float $weight = 0,
  ) {
    parent::__construct($entity_type, $bundle, $field_name, $field_type, $constraint, $weight);
  }

  /**
   * {@inheritdoc}
   */
  public function matches(FieldContext $context): bool {
    if (!$context instanceof PropertyContext || !parent::matches($context)) {
      return FALSE;
    }

    return $this->accepts($this->property_name, $context->propertyName)
      && $this->accepts($this->data_type, $context->propertyDefinition->getDataType());
  }

}
