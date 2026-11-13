<?php

declare(strict_types=1);

namespace Drupal\schema_builder\Event;

use Drupal\schema_builder\Context\PropertyContext;

/**
 * Carries one property of a field item.
 */
final class PropertySchemaEvent extends SchemaEvent {

  /**
   * Class constructor.
   *
   * @param \Drupal\schema_builder\Context\PropertyContext $context
   *   The property being described.
   * @param array $schema
   *   The schema so far, holding only what the walker assembled.
   */
  public function __construct(
    private readonly PropertyContext $context,
    array $schema,
  ) {
    parent::__construct($schema);
  }

  /**
   * {@inheritdoc}
   */
  public function getContext(): PropertyContext {
    return $this->context;
  }

}
