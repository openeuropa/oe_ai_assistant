<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSession;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for AI editorial sessions.
 */
class AiEditorialSessionTest extends ExistingSiteBase {

  /**
   * Tests the drafting bundle is installed and sessions can be persisted.
   */
  public function testDraftingBundleAndCrud(): void {
    $bundle = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session_type')
      ->load('drafting');

    $this->assertNotNull($bundle);
    $this->assertSame('Drafting', $bundle->label());

    $user = $this->createUser();

    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session')
      ->create([
        'bundle' => 'drafting',
        'uid' => $user->id(),
        'content_type' => 'oe_news',
        'template_id' => 'landing_page',
      ]);

    $session->save();

    $loaded = AiEditorialSession::load($session->id());
    $this->assertNotNull($loaded);
    $this->assertSame('drafting', $loaded->bundle());
    $this->assertSame('oe_news', $loaded->getContentType());
    $this->assertSame(AiEditorialSession::STATUS_ACTIVE, $loaded->getStatus());
    $this->assertSame((string) $user->id(), (string) $loaded->getOwnerId());
    $this->assertStringContainsString('Drafting - ', $loaded->label());

    $loaded->setStatus(AiEditorialSession::STATUS_COMPLETED);
    $loaded->set('base_revision_id', 42);
    $loaded->save();

    $reloaded = AiEditorialSession::load($session->id());
    $this->assertSame(AiEditorialSession::STATUS_COMPLETED, $reloaded?->getStatus());
    $this->assertSame(42, (int) $reloaded?->get('base_revision_id')->value);

    $reloaded?->delete();
    $this->assertNull(AiEditorialSession::load($session->id()));
  }

}
