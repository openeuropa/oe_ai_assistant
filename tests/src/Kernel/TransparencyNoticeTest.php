<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Service\TransparencyNoticeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shared transparency notice service.
 */
#[Group('oe_ai_assistant')]
class TransparencyNoticeTest extends AiEditorialSessionKernelTestBase {

  /**
   * The configured notice is returned unchanged when it uses allowed markup.
   */
  public function testConfiguredNotice(): void {
    $notice = '<strong>AI-generated content</strong>. <a href="https://example.com">Read more</a>.';
    $this->config('oe_ai_assistant.settings')
      ->set('transparency_notice', $notice)
      ->save();

    $service = $this->container->get('oe_ai_assistant.transparency_notice');
    $this->assertInstanceOf(TransparencyNoticeInterface::class, $service);
    $this->assertSame($notice, $service->getNotice());
  }

  /**
   * Values introduced outside the settings form are sanitized on output.
   */
  public function testConfiguredNoticeIsSanitizedOnOutput(): void {
    $this->config('oe_ai_assistant.settings')
      ->set('transparency_notice', '<strong>Safe</strong><script>alert(1)</script><u>Removed</u>')
      ->save();

    $service = $this->container->get('oe_ai_assistant.transparency_notice');
    $notice = $service->getNotice();

    $this->assertStringContainsString('<strong>Safe</strong>', $notice);
    $this->assertStringNotContainsString('<script>', $notice);
    $this->assertStringNotContainsString('<u>', $notice);
  }

  /**
   * The settings object is available to consumers as cacheability metadata.
   */
  public function testReturnsSettingsConfig(): void {
    $service = $this->container->get('oe_ai_assistant.transparency_notice');

    $this->assertSame(
      'oe_ai_assistant.settings',
      $service->getConfig()->getName()
    );
  }

}
