<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use JsonSchema\Validator;

/**
 * Validates request bodies against OpenAPI schemas.
 *
 * Reads the consolidated schemas.json from the dist/ directory (produced
 * by the React app build step) and uses justinrainbow/json-schema for
 * validation.
 */
class RequestValidator {

  /**
   * Cached schema definitions from schemas.json.
   *
   * @var array<string, object>|null
   */
  private ?array $schemas = NULL;

  /**
   * Validates a raw JSON string against a named schema.
   *
   * Decodes the JSON as objects (not arrays) so the json-schema
   * validator sees {} as an object rather than an empty array.
   *
   * @param string $rawJson
   *   The raw JSON string from the request body.
   * @param string $schemaName
   *   The schema name (e.g. 'EchoRequest').
   *
   * @return string[]
   *   Array of validation error messages. Empty if valid.
   */
  public function validateRaw(string $rawJson, string $schemaName): array {
    $schemas = $this->loadSchemas();

    if (!isset($schemas[$schemaName])) {
      return [sprintf("Unknown schema '%s'.", $schemaName)];
    }

    $schema = $schemas[$schemaName];

    // Decode as objects (not associative arrays) so the validator
    // sees {} as stdClass, not an empty PHP array.
    $data = json_decode($rawJson);
    if ($data === NULL && json_last_error() !== JSON_ERROR_NONE) {
      return ['Invalid JSON in request body.'];
    }

    $validator = new Validator();
    $validator->validate($data, $schema);

    if ($validator->isValid()) {
      return [];
    }

    $errors = [];
    foreach ($validator->getErrors() as $error) {
      $path = $error['property'] ? $error['property'] . ': ' : '';
      $errors[] = $path . $error['message'];
    }

    return $errors;
  }

  /**
   * Loads and caches schema definitions from dist/schemas.json.
   *
   * @return array<string, object>
   */
  private function loadSchemas(): array {
    if ($this->schemas !== NULL) {
      return $this->schemas;
    }

    // Resolve the module root from this file's location:
    // src/Service/RequestValidator.php -> module root is two levels up.
    $moduleRoot = dirname(__DIR__, 2);
    $schemaFile = $moduleRoot . '/dist/schemas.json';

    if (!file_exists($schemaFile)) {
      $this->schemas = [];
      return $this->schemas;
    }

    $raw = json_decode(file_get_contents($schemaFile), FALSE);

    if (!is_object($raw)) {
      $this->schemas = [];
      return $this->schemas;
    }

    // Build a flat map of schema name to schema definition object.
    $this->schemas = [];
    foreach ($raw as $name => $definition) {
      $this->schemas[$name] = $definition;
    }

    return $this->schemas;
  }

}
