<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder;

use Drupal\schema_builder\Context\ItemContext;
use Drupal\schema_builder\Event\FieldItemSchemaEvent;

/**
 * Base class for a builder that describes one item of a field.
 */
abstract class FieldItemSchemaBuilderBase extends BuilderBase {

  /**
   * Returns the schema with this builder's part of it filled in.
   */
  abstract protected function build(ItemContext $context, array $schema): array;

  /**
   * {@inheritdoc}
   */
  protected static function eventClass(): string {
    return FieldItemSchemaEvent::class;
  }

  /**
   * Hands the schema to the builder when the attribute matches the context.
   */
  public function onSchema(FieldItemSchemaEvent $event): void {
    $context = $event->getContext();
    if (static::attribute()->matches($context)) {
      $event->setSchema($this->build($context, $event->getSchema()));
    }
  }

}
