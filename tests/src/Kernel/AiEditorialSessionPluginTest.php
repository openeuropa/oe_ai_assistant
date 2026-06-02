<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionPlugin;
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
    $this->assertTrue($loaded->access('update', $owner));
    $this->assertFalse($loaded->access('view', $viewer));
    $this->assertTrue($loaded->access('delete', $admin));
    $this->assertFalse($loaded->access('delete', $owner));

    $loaded->delete();
    $this->assertNull(AiEditorialSessionPlugin::load($plugin->id()));
  }

}
