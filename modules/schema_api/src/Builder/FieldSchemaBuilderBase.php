<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder;

use Drupal\schema_api\Context\FieldContext;
use Drupal\schema_api\Event\FieldSchemaEvent;

/**
 * Base class for a builder that describes the array wrapper of a field.
 */
abstract class FieldSchemaBuilderBase extends SchemaBuilderBase {

  /**
   * Returns the schema with this builder's part of it filled in.
   *
   * @return array|null
   *   The schema, or NULL to leave the field out of the bundle altogether.
   */
  abstract protected function build(FieldContext $context, array $schema): ?array;

  /**
   * {@inheritdoc}
   */
  protected static function eventClass(): string {
    return FieldSchemaEvent::class;
  }

  /**
   * Hands the schema to the builder when the attribute matches the context.
   */
  public function onSchema(FieldSchemaEvent $event): void {
    $context = $event->getContext();
    if (!static::attribute()->matches($context)) {
      return;
    }

    $schema = $this->build($context, $event->getSchema());
    if ($schema === NULL) {
      $event->skip();
      return;
    }

    $event->setSchema($schema);
  }

}
