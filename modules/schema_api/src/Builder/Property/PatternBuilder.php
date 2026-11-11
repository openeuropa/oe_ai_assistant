<?php

declare(strict_types=1);

namespace Drupal\schema_api\Builder\Property;

use Drupal\schema_api\Attribute\PropertySchemaBuilder;
use Drupal\schema_api\Builder\PropertySchemaBuilderBase;
use Drupal\schema_api\Context\PropertyContext;

/**
 * Carries the regular expression of a Regex constraint into the schema.
 */
#[PropertySchemaBuilder(
  constraint: 'Regex',
)]
final class PatternBuilder extends PropertySchemaBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function build(PropertyContext $context, array $schema): array {
    $options = $context->getConstraint('Regex');

    // A PHP pattern is wrapped in delimiters and may carry modifiers after
    // the closing one, neither of which a JSON Schema pattern takes. What is
    // left is PCRE, which overlaps the ECMA-262 syntax the specification
    // asks for without being the same thing.
    $pattern = (string) $options['pattern'];
    $delimiter = $pattern[0];
    $closing = strrpos($pattern, $delimiter);
    $pattern = $closing > 0 ? substr($pattern, 1, $closing - 1) : $pattern;

    // A Regex with 'match' turned off asserts the value does NOT match, so
    // the pattern goes inside a negation.
    if ($options['match'] ?? TRUE) {
      $schema['pattern'] = $pattern;
    }
    else {
      $schema['not'] = ['pattern' => $pattern];
    }

    return $schema;
  }

}
