<?php

declare(strict_types=1);

namespace Drupal\schema_builder\SchemaBuilder;

use Drupal\schema_builder\Attribute\BuilderBase as BuilderAttribute;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes a builder to the schema event its level fires.
 *
 * The listener itself lives on the base class of each level, so that a
 * builder is handed the context type its level carries.
 */
abstract class BuilderBase implements EventSubscriberInterface {

  /**
   * The attribute of each builder class, read once per class.
   */
  private static array $attributes = [];

  /**
   * Returns the event class the builder subscribes to.
   */
  abstract protected static function eventClass(): string;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // The dispatcher takes an integer and runs the highest first, while a
    // builder declares a float and the heaviest is meant to have the last
    // word. Negating the scaled weight gives both. Three decimal places are
    // enough to slot a builder between two others without renumbering them.
    //
    // These priorities share no scale with anything else, so only builders
    // may subscribe to the schema events.
    $priority = (int) round(static::attribute()->weight * -1000);

    return [static::eventClass() => ['onSchema', $priority]];
  }

  /**
   * Reads the attribute off the builder class.
   *
   * Reflection happens once per class, because getSubscribedEvents() runs
   * while the container is compiled and onSchema() runs once per field.
   */
  protected static function attribute(): BuilderAttribute {
    if (!isset(self::$attributes[static::class])) {
      $attributes = (new \ReflectionClass(static::class))
        ->getAttributes(BuilderAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);

      if ($attributes === []) {
        throw new \LogicException(sprintf('%s carries no schema builder attribute.', static::class));
      }
      self::$attributes[static::class] = $attributes[0]->newInstance();
    }

    return self::$attributes[static::class];
  }

}
