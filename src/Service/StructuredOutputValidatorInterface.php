<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Validates model output against the JSON schema it was asked to produce.
 */
interface StructuredOutputValidatorInterface {

  /**
   * Checks decoded model output against a JSON schema.
   *
   * @param array $data
   *   The decoded model output.
   * @param array $schema
   *   The JSON schema the output is expected to satisfy.
   *
   * @return array
   *   One message per problem, phrased in the schema's own terms and
   *   prefixed with the path of the offending value, ready to hand back to
   *   the model. Empty when the output satisfies the schema.
   */
  public function validate(array $data, array $schema): array;

}
