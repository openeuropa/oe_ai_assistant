<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use JsonSchema\Validator;

/**
 * Validates model output against the JSON schema it was asked to produce.
 */
class StructuredOutputValidator implements StructuredOutputValidatorInterface {

  /**
   * {@inheritdoc}
   */
  public function validate(array $data, array $schema): array {
    // The root of a group's schema is always an object, so an answer that
    // decoded to an empty array is an empty object, not an empty list.
    // Without the cast the model would be told its object is an array,
    // which says nothing about the fields it actually left out.
    $value = $this->toObjectTree((object) $data);
    // Held in a variable because the validator takes it by reference.
    $expected = $this->toObjectTree($schema);

    $validator = new Validator();
    $validator->validate($value, $expected);

    if ($validator->isValid()) {
      return [];
    }

    $messages = [];
    foreach ($validator->getErrors() as $error) {
      // An error on the whole object carries no property path.
      $property = ($error['property'] ?? '') !== ''
        ? $error['property']
        : 'the returned object';
      $messages[] = sprintf('%s: %s', $property, $error['message']);
    }

    // A single wrong value inside a oneOf reports once per rejected variant,
    // and those messages repeat verbatim. Telling the model the same thing
    // five times only crowds out the problems it has not seen yet.
    return array_values(array_unique($messages));
  }

  /**
   * Converts a PHP array tree into the object tree the validator expects.
   *
   * Re-encoding preserves the distinction the validator relies on: a keyed
   * array becomes an object, a list stays an array. Casting the tree instead
   * would make an empty object indistinguishable from an empty list.
   *
   * @param mixed $value
   *   The tree to convert.
   *
   * @return mixed
   *   The same tree with every keyed array turned into a stdClass.
   */
  private function toObjectTree(mixed $value): mixed {
    return json_decode(json_encode($value, JSON_THROW_ON_ERROR), FALSE, 512, JSON_THROW_ON_ERROR);
  }

}
