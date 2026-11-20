<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

/**
 * Caps a single document extract at a safe character budget.
 *
 * EditorialSessionAgentBase renders every attached document into its prompt,
 * and ReadDocumentNeuronTool returns one of them in full on demand. Both have
 * to keep a single extract from flooding the model's context, so they share
 * the cap from here instead of one reaching into the other.
 */
final class DocumentExtractTruncator {

  /**
   * Characters of one document's extracted text we hand to the model.
   */
  public const int MAX_DOCUMENT_CHARS = 20000;

  /**
   * Caps an extract, and reports whether anything was cut off.
   *
   * @param string $extract
   *   The extract to cap.
   *
   * @return array
   *   Two elements: the extract, cut to MAX_DOCUMENT_CHARS if it was over,
   *   and whether it was cut.
   */
  public static function truncate(string $extract): array {
    if (mb_strlen($extract) <= self::MAX_DOCUMENT_CHARS) {
      return [$extract, FALSE];
    }

    return [mb_substr($extract, 0, self::MAX_DOCUMENT_CHARS), TRUE];
  }

}
