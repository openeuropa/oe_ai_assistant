<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

/**
 * Reads the string a tool returned as the array the transcript stores.
 *
 * The tools answer with JSON; a result that is not a JSON array or object
 * is kept as its text, so the transcript, the stream and the event summary
 * all see one shape.
 */
final class ToolResult {

  /**
   * Decodes a tool result, keeping plain text under a "text" key.
   */
  public static function decode(string $result): array {
    $decoded = json_decode($result, TRUE);
    return is_array($decoded) ? $decoded : ['text' => $result];
  }

}
