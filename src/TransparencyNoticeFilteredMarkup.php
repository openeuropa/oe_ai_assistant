<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\MarkupTrait;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;

/**
 * Defines a safe string for the AI transparency notice.
 *
 * Modelled on core's FieldFilteredMarkup: it filters the string with a very
 * restrictive tag list when created, but uses a narrower list of its own and
 * does not depend on that @internal core class.
 *
 * @see \Drupal\Core\Field\FieldFilteredMarkup
 */
final class TransparencyNoticeFilteredMarkup implements MarkupInterface, \Countable {

  // Provides safe-string storage plus __toString() and jsonSerialize().
  use MarkupTrait;

  /**
   * HTML tags supported in the transparency notice.
   *
   * @var string[]
   */
  public const ALLOWED_TAGS = ['b', 'i', 'a', 'strong', 'em'];

  /**
   * Overrides \Drupal\Component\Render\MarkupTrait::create().
   *
   * @param string $string
   *   The raw, untrusted notice text.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   A safe string filtered with the allowed tag list and normalized.
   *
   * @see \Drupal\oe_ai_assistant\TransparencyNotice::allowedTags()
   * @see \Drupal\Component\Utility\Xss::filter()
   * @see \Drupal\Component\Utility\Html::normalize()
   */
  public static function create($string): string|TransparencyNoticeFilteredMarkup {
    $string = (string) $string;
    if ($string === '') {
      return '';
    }
    $safe_string = new self();
    // Xss::filter() removes every tag outside the allowed list and all known
    // XSS vectors; Html::normalize() only tidies the resulting fragment, so
    // the value is safe to print as is.
    $safe_string->string = Html::normalize(Xss::filter($string, self::allowedTags()));
    return $safe_string;
  }

  /**
   * Returns the allowed tag list.
   *
   * @return string[]
   *   A list of allowed tags.
   */
  public static function allowedTags(): array {
    return self::ALLOWED_TAGS;
  }

  /**
   * Returns a human-readable list of allowed tags for display in help texts.
   *
   * @return string
   *   A human-readable list of allowed tags for display in help texts.
   */
  public static function displayAllowedTags(): string {
    return '<' . implode('> <', self::allowedTags()) . '>';
  }

}
