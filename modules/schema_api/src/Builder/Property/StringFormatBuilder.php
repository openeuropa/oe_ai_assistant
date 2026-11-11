<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder\Property;

use Drupal\schema_api\Attribute\PropertySchemaBuilder;
use Drupal\schema_api\Builder\PropertySchemaBuilderBase;
use Drupal\schema_api\Context\PropertyContext;

/**
 * Names the shape of a string, from the constraints that imply one.
 */
#[PropertySchemaBuilder(
  constraint: ['Email', 'Uuid', 'UriHost', 'DateTimeFormat'],
)]
final class StringFormatBuilder extends PropertySchemaBuilderBase {

  /**
   * The format each constraint stands for.
   */
  private const FORMATS = [
    'Email' => 'email',
    'Uuid' => 'uuid',
    'UriHost' => 'hostname',
    'DateTimeFormat' => 'date-time',
  ];

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    foreach (self::FORMATS as $constraint => $format) {
      if ($context->getConstraint($constraint) !== NULL) {
        $schema['format'] = $format;
        break;
      }
    }

    return $schema;
  }

}
