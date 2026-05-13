<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\AiEditorialSession;

/**
 * Kernel tests for AI editorial sessions.
 */
class AiEditorialSessionTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests the content_creation bundle metadata and session CRUD lifecycle.
   */
  public function testContentCreationCrud(): void {
    $bundle = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session_type')
      ->load('content_creation');

    $this->assertNotNull($bundle);
    $this->assertSame('Content creation', $bundle->label());

    $user = $this->createUser();

    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->create([
        'type' => 'content_creation',
        'label' => 'Content creation',
        'uid' => $user->id(),
        'content_type' => 'oe_news',
      ]);
    $session->save();

    $loaded = AiEditorialSession::load($session->id());
    $this->assertNotNull($loaded);
    $this->assertSame('content_creation', $loaded->bundle());
    $this->assertSame('oe_news', $loaded->getContentType());
    $this->assertSame(AiEditorialSession::STATUS_ACTIVE, $loaded->getStatus());
    $this->assertSame((string) $user->id(), (string) $loaded->getOwnerId());
    $this->assertStringStartsWith('Content creation', $loaded->label());

    $entity_type = $this->container->get('entity_type.manager')
      ->getDefinition('ai_editorial_session');
    $this->assertFalse($entity_type->hasLinkTemplate('edit-form'));
    $this->assertNull($entity_type->getFormClass('edit'));

    $loaded->setStatus(AiEditorialSession::STATUS_COMPLETED);
    $loaded->save();

    $reloaded = AiEditorialSession::load($session->id());
    $this->assertSame(AiEditorialSession::STATUS_COMPLETED, $reloaded?->getStatus());

    $reloaded?->delete();
    $this->assertNull(AiEditorialSession::load($session->id()));
  }

}
