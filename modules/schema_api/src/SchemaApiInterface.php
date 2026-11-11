<?php

declare(strict_types=1);

namespace Drupal\schema_api;

/**
 * Describes a content entity bundle as a JSON Schema.
 */
interface SchemaApiInterface {

  /**
   * Builds the schema of one bundle.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   * @param array $parameters
   *   Anything the caller wants the builders to see, such as the template a
   *   draft is being written from. SchemaApi passes it through untouched and
   *   carries it into every bundle it walks into.
   *
   * @return array
   *   A JSON Schema object describing a single entity of the bundle, in the
   *   shape the entity denormalizer accepts.
   */
  public function get(string $entityTypeId, string $bundle, array $parameters = []): array;

}
