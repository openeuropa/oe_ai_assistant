<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Interface for the draft saving service.
 *
 * Validates, builds, and saves a drafted node from LLM-produced
 * field values.
 */
interface DraftSaverInterface {

  /**
   * Validates, builds, and saves a drafted node.
   *
   * @param string $bundle
   *   The content type bundle machine name (e.g. "oe_news").
   * @param array $fields
   *   The LLM-produced fields map, keyed by field machine name.
   *   Values are in the Drupal serialization format (arrays of
   *   items, e.g. [["value" => "Title"]]).
   *
   * @return array
   *   An array with 'nodeId' (string) and 'previewUrl' (string).
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   - 'invalid_bundle' (400) if the bundle does not exist.
   *   - 'forbidden' (403) if the user lacks create permission.
   *   - 'invalid_payload' (400) if the entity builder rejects
   *     the payload.
   */
  public function save(string $bundle, array $fields): array;

}
