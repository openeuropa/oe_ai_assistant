<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder;

use Drupal\schema_builder\Context\PropertyContext;
use Drupal\schema_builder\Event\PropertySchemaEvent;

/**
 * Base class for a builder that describes one property of a field item.
 */
abstract class PropertySchemaBuilderBase extends BuilderBase {

  /**
   * Returns the schema with this builder's part of it filled in.
   */
  abstract protected function build(PropertyContext $context, array $schema): array;

  /**
   * {@inheritdoc}
   */
  protected static function eventClass(): string {
    return PropertySchemaEvent::class;
  }

  /**
   * Hands the schema to the builder when the attribute matches the context.
   */
  public function onSchema(PropertySchemaEvent $event): void {
    $context = $event->getContext();
    if (static::attribute()->matches($context)) {
      $event->setSchema($this->build($context, $event->getSchema()));
    }
  }

}
