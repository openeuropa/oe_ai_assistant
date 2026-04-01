<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\oe_ai_assistant\Store\DrupalTempMessageStore;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Unit tests for DrupalTempMessageStore.
 */
class DrupalTempMessageStoreTest extends TestCase {

  /**
   * Tests that load returns an empty MessageBag for new threads.
   */
  public function testLoadReturnsEmptyBagForNewThread(): void {
    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with('thread-1')->willReturn(NULL);

    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $factory->method('get')->with('oe_ai_drafting')->willReturn($store);

    $messageStore = new DrupalTempMessageStore(
      $factory, 'oe_ai_drafting', 'thread-1',
    );

    $bag = $messageStore->load();
    $this->assertInstanceOf(MessageBag::class, $bag);
    $this->assertCount(0, $bag->getMessages());
  }

  /**
   * Tests save and load round-trip.
   */
  public function testSaveAndLoad(): void {
    $stored = NULL;

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('set')->willReturnCallback(
      function (string $key, mixed $value) use (&$stored): void {
        $stored = $value;
      }
    );
    $store->method('get')->willReturnCallback(
      function () use (&$stored) {
        return $stored;
      }
    );

    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $factory->method('get')->willReturn($store);

    $messageStore = new DrupalTempMessageStore($factory, 'test', 'thread-1');

    $bag = new MessageBag(
      Message::ofUser('Hello'),
      Message::ofAssistant('Hi there'),
    );
    $messageStore->save($bag);

    $loaded = $messageStore->load();
    $this->assertCount(2, $loaded->getMessages());
  }

  /**
   * Tests that save trims messages beyond max limit.
   */
  public function testSaveTrimsToMaxMessages(): void {
    $stored = NULL;

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('set')->willReturnCallback(
      function (string $key, mixed $value) use (&$stored): void {
        $stored = $value;
      }
    );
    $store->method('get')->willReturnCallback(
      function () use (&$stored) {
        return $stored;
      }
    );

    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $factory->method('get')->willReturn($store);

    // Max 4 messages.
    $messageStore = new DrupalTempMessageStore(
      $factory, 'test', 'thread-1', 4,
    );

    $bag = new MessageBag(
      Message::ofUser('msg1'),
      Message::ofAssistant('reply1'),
      Message::ofUser('msg2'),
      Message::ofAssistant('reply2'),
      Message::ofUser('msg3'),
      Message::ofAssistant('reply3'),
    );
    $messageStore->save($bag);

    $loaded = $messageStore->load();
    // Should keep only the last 4 messages.
    $this->assertCount(4, $loaded->getMessages());
  }

  /**
   * Tests that drop deletes the stored data.
   */
  public function testDrop(): void {
    $store = $this->createMock(PrivateTempStore::class);
    $store->expects($this->once())
      ->method('delete')
      ->with('thread-1');

    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $factory->method('get')->willReturn($store);

    $messageStore = new DrupalTempMessageStore(
      $factory, 'test', 'thread-1',
    );
    $messageStore->drop();
  }

}
