<?php

declare(strict_types=1);

namespace Drupal\schema_api\Event;

use Drupal\schema_api\Context\ItemContext;

/**
 * Carries the object describing one item of a field.
 */
final class FieldItemSchemaEvent extends SchemaEvent {

  /**
   * Class constructor.
   *
   * @param \Drupal\schema_api\Context\ItemContext $context
   *   The item being described.
   * @param array $schema
   *   The schema so far, holding only what the walker assembled.
   */
  public function __construct(
    private readonly ItemContext $context,
    array $schema,
  ) {
    parent::__construct($schema);
  }

  /**
   * {@inheritdoc}
   */
  public function getContext(): ItemContext {
    return $this->context;
  }

}
