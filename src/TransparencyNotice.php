<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

/**
 * Sanitisation contract for the AI transparency notice.
 */
final class TransparencyNotice {

  /**
   * HTML tags supported in the transparency notice.
   *
   * @var string[]
   */
  public const ALLOWED_TAGS = ['b', 'i', 'a', 'strong', 'em'];

}
