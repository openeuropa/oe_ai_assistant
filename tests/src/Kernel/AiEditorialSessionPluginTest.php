<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionPlugin;
use Drupal\oe_ai_assistant\Service\AiEditorialSessionPluginStore;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for AI editorial session plugin instances.
 */
#[Group('oe_ai_assistant')]
class AiEditorialSessionPluginTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests plugin instance CRUD, fields, defaults, and parent access.
   */
  public function testPluginInstanceCrudAndAccess(): void {
    $owner = $this->createUser();
    $viewer = $this->createUser();
    $admin = $this->createUser(['administer ai editorial sessions']);
    $session = $this->createSession($owner);
    $access_handler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler('ai_editorial_session_plugin');

    $this->assertTrue($access_handler->createAccess(NULL, $admin));
    $this->assertFalse($access_handler->createAccess(NULL, $owner));

    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionPluginInterface $plugin */
    $plugin = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session_plugin')
      ->create([
        'session' => $session->id(),
        'plugin_id' => 'drafting',
        'configuration' => [
          'content_type' => 'oe_news',
        ],
        'state' => [
          'threadId' => 'thread-1',
        ],
        'weight' => 10,
      ]);
    $plugin->save();

    $loaded = AiEditorialSessionPlugin::load($plugin->id());

    $this->assertNotNull($loaded);
    $this->assertSame((string) $session->id(), (string) $loaded->getSession()->id());
    $this->assertSame('drafting', $loaded->getPluginId());
    $this->assertSame(AiEditorialSessionPlugin::STATUS_ACTIVE, $loaded->getStatus());
    $this->assertSame(['content_type' => 'oe_news'], $loaded->getConfiguration());
    $this->assertSame(['threadId' => 'thread-1'], $loaded->getState());
    $this->assertSame('thread-1', $loaded->getStateValue('threadId'));
    $this->assertSame('fallback', $loaded->getStateValue('missing', 'fallback'));
    $this->assertSame('10', (string) $loaded->get('weight')->value);

    $loaded
      ->setStatus(AiEditorialSessionPlugin::STATUS_COMPLETED)
      ->setConfiguration(['content_type' => 'oe_contact'])
      ->setState(['threadId' => 'thread-2'])
      ->setStateValue('lastMessageId', 'message-1');
    $loaded->save();

    $reloaded = AiEditorialSessionPlugin::load($plugin->id());
    $this->assertSame(AiEditorialSessionPlugin::STATUS_COMPLETED, $reloaded?->getStatus());
    $this->assertSame(['content_type' => 'oe_contact'], $reloaded?->getConfiguration());
    $this->assertSame([
      'threadId' => 'thread-2',
      'lastMessageId' => 'message-1',
    ], $reloaded?->getState());

    $this->assertTrue($loaded->access('view', $owner));
    $this->assertTrue($loaded->access('view label', $owner));
    $this->assertTrue($loaded->access('update', $owner));
    $this->assertFalse($loaded->access('view', $viewer));
    $this->assertFalse($loaded->access('update', $viewer));
    $this->assertTrue($loaded->access('delete', $admin));
    $this->assertFalse($loaded->access('delete', $owner));

    $loaded->delete();
    $this->assertNull(AiEditorialSessionPlugin::load($plugin->id()));
  }

  /**
   * Tests the plugin instance repository service.
   */
  public function testPluginInstanceStore(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $other_session = $this->createSession($owner);

    /** @var \Drupal\oe_ai_assistant\Service\AiEditorialSessionPluginStore $store */
    $store = $this->container->get(AiEditorialSessionPluginStore::class);

    $this->assertNull($store->loadForSession($session, 'drafting'));

    $plugin = $store->loadOrCreateForSession($session, 'drafting', [
      'content_type' => 'oe_news',
    ]);

    $this->assertSame('drafting', $plugin->getPluginId());
    $this->assertSame((string) $session->id(), (string) $plugin->getSession()->id());
    $this->assertSame(['content_type' => 'oe_news'], $plugin->getConfiguration());
    $this->assertSame((string) $plugin->id(), (string) $store->loadForSession($session, 'drafting')?->id());
    $this->assertSame((string) $plugin->id(), (string) $store->loadOrCreateForSession($session, 'drafting')?->id());

    $updated = $store->saveState($session, 'drafting', [
      'threadId' => 'thread-1',
    ]);
    $this->assertSame(['threadId' => 'thread-1'], $updated->getState());

    $notes = $store->loadOrCreateForSession($session, 'notes');
    $other_drafting = $store->loadOrCreateForSession($other_session, 'drafting');
    $other_drafting->setStatus(AiEditorialSessionPlugin::STATUS_COMPLETED);
    $other_drafting->save();

    $active_drafting = $store->loadActiveByPlugin('drafting');
    $this->assertArrayHasKey($plugin->id(), $active_drafting);
    $this->assertArrayNotHasKey($other_drafting->id(), $active_drafting);
    $this->assertArrayNotHasKey($notes->id(), $active_drafting);

    $this->assertSame(1, $store->deleteForSession($session, 'notes'));
    $this->assertNull($store->loadForSession($session, 'notes'));

    $this->assertSame(1, $store->deleteForSession($session));
    $this->assertNull($store->loadForSession($session, 'drafting'));
    $this->assertNotNull($store->loadForSession($other_session, 'drafting'));
  }

}
