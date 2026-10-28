<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Decodes the JSON columns a stored Neuron message carries.
 */
final class NeuronMessageJson {

  /**
   * Decodes one of the JSON columns of a stored message.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $row
   *   The stored message.
   * @param string $field
   *   The column: content, which is a list of content blocks, or meta.
   *
   * @return array
   *   The decoded value, empty when the column holds nothing.
   */
  public static function decode(ContentEntityInterface $row, string $field): array {
    $value = $row->get($field)->value;

    return $value === NULL ? [] : (array) json_decode((string) $value, TRUE);
  }

  /**
   * Decodes what a tool answered, which the tools of this module write as JSON.
   *
   * @param mixed $result
   *   The result as the call serialized it.
   *
   * @return array
   *   The decoded result, empty when it is not a JSON object.
   */
  public static function decodeResult(mixed $result): array {
    if (is_array($result)) {
      return $result;
    }

    return is_string($result) ? (array) json_decode($result, TRUE) : [];
  }

}
