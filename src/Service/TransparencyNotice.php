<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

/**
 * Loads and sanitizes the AI transparency notice.
 */
final class TransparencyNotice implements TransparencyNoticeInterface {

  /**
   * HTML tags supported in the transparency notice.
   *
   * @var string[]
   */
  private const ALLOWED_TAGS = ['b', 'i', 'a', 'strong', 'em'];

  /**
   * Constructs the transparency notice service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getNotice(): string {
    return $this->sanitize((string) $this->getConfig()->get('transparency_notice'));
  }

  /**
   * {@inheritdoc}
   */
  public function sanitize(string $value): string {
    return Xss::filter($value, self::ALLOWED_TAGS);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('oe_ai_assistant.settings');
  }

}
