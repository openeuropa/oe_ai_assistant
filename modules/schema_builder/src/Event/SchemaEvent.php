<?php

declare(strict_types=1);

namespace Drupal\schema_builder\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\schema_builder\Context\FieldContext;

/**
 * Carries a piece of schema past the builders that may add to it.
 *
 * SchemaBuilder walks the entity and assembles the nesting. Every keyword
 * in the result comes from a builder subscribed to one of the subclasses.
 *
 * Each subclass holds its own context and narrows getContext() to it, so a
 * builder is handed the type it works with and never has to check.
 */
abstract class SchemaEvent extends Event {

  /**
   * Class constructor.
   *
   * @param array $schema
   *   The schema so far, holding only what the walker assembled.
   */
  public function __construct(private array $schema) {}

  /**
   * Returns what is being described.
   */
  abstract public function getContext(): FieldContext;

  /**
   * Returns the schema as the last builder left it.
   */
  public function getSchema(): array {
    return $this->schema;
  }

  /**
   * Replaces the schema.
   */
  public function setSchema(array $schema): void {
    $this->schema = $schema;
  }

}
