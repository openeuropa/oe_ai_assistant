<?php

declare(strict_types=1);

namespace Drupal\schema_builder\Event;

use Drupal\schema_builder\Context\FieldContext;

/**
 * Carries the array wrapper of one field.
 */
final class FieldSchemaEvent extends SchemaEvent {

  /**
   * Class constructor.
   *
   * @param \Drupal\schema_builder\Context\FieldContext $context
   *   The field being described.
   * @param array $schema
   *   The schema so far, holding only what the walker assembled.
   */
  public function __construct(
    private readonly FieldContext $context,
    array $schema,
  ) {
    parent::__construct($schema);
  }

  /**
   * Whether a builder asked for the field to be left out.
   */
  private bool $skipped = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getContext(): FieldContext {
    return $this->context;
  }

  /**
   * Leaves the field out of the bundle.
   *
   * A builder shapes the schema of its own field and cannot reach the map it
   * sits in, so this is how it says the field does not belong there at all.
   */
  public function skip(): void {
    $this->skipped = TRUE;
  }

  /**
   * Checks whether a builder asked for the field to be left out.
   */
  public function isSkipped(): bool {
    return $this->skipped;
  }

}
