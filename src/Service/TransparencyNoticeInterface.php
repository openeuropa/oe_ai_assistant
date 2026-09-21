<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Config\ImmutableConfig;

/**
 * Provides the configured AI transparency notice.
 */
interface TransparencyNoticeInterface {

  /**
   * Returns the configured notice after applying the allowed HTML policy.
   */
  public function getNotice(): string;

  /**
   * Sanitizes a notice value using the configured HTML policy.
   */
  public function sanitize(string $value): string;

  /**
   * Returns the settings config object for cacheability metadata.
   */
  public function getConfig(): ImmutableConfig;

}
