<?php

declare(strict_types=1);

namespace Drupal\schema_api\Attribute;

/**
 * Marks a class that builds part of the schema of a field.
 */
#[\Attribute(
  \Attribute::TARGET_CLASS,
)]
final class FieldSchemaBuilder extends SchemaBuilderBase {}
