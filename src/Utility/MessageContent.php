<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Utility;

/**
 * Reads the content of a stored Neuron message.
 */
final class MessageContent {

  /**
   * Returns the text the content holds.
   *
   * @param array $content
   *   The decoded content of a message or a tool result: a list of content
   *   blocks.
   *
   * @return string
   *   The text of every text block, joined.
   */
  public static function toText(array $content): string {
    // Neuron keeps a message's content as a list of blocks, and only a block
    // of type 'text' holds any text.
    return implode('', array_map(
      static fn (array $block): string => (string) ($block['content'] ?? ''),
      array_filter($content, static fn ($block): bool => is_array($block) && ($block['type'] ?? '') === 'text'),
    ));
  }

}
