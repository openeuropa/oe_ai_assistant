<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for AI conversation message access control.
 */
#[Group('oe_ai_assistant')]
class AiConversationMessageAccessTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests the AND of the flat permission and the host session's access.
   */
  public function testSessionHostAccessIsAnded(): void {
    $owner = $this->createUser(['create oe_news content']);
    $node = $this->createPublishedNode('oe_news', 'Shared node');
    $node->setOwnerId((int) $owner->id())->save();
    $session = $this->createSession($owner, $node);

    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message */
    $message = $this->container->get('entity_type.manager')
      ->getStorage('ai_conversation_message')
      ->create([
        'host_entity_type' => 'ai_editorial_session',
        'host_entity_id' => (int) $session->id(),
        'role' => AiConversationMessageInterface::ROLE_USER,
        'content' => 'Hello',
      ]);
    $message->save();

    // Flat permission present, node access present: allowed. The session
    // host also requires 'use oe ai assistant' now, so it's granted here to
    // isolate the node-access tier being tested.
    $collaborator = $this->createUser([
      'use oe ai assistant',
      'access ai conversation message overview',
      'edit ai conversation message',
      'access content',
      'edit any oe_news content',
    ]);
    $this->assertTrue($message->access('view', $collaborator));
    $this->assertTrue($message->access('update', $collaborator));

    // Flat permission present, node access absent: denied.
    $noNodeAccess = $this->createUser([
      'use oe ai assistant',
      'access ai conversation message overview',
      'edit ai conversation message',
    ]);
    $this->assertFalse($message->access('view', $noNodeAccess));
    $this->assertFalse($message->access('update', $noNodeAccess));

    // Flat permission absent, node access present: denied.
    $noFlatPermission = $this->createUser(['use oe ai assistant', 'access content', 'edit any oe_news content']);
    $this->assertFalse($message->access('view', $noFlatPermission));
    $this->assertFalse($message->access('update', $noFlatPermission));

    // Neither: denied.
    $neither = $this->createUser();
    $this->assertFalse($message->access('view', $neither));
  }

  /**
   * Tests non-session host types keep today's flat-permission-only behavior.
   */
  public function testNonSessionHostTypeIsUnaffected(): void {
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message */
    $message = $this->container->get('entity_type.manager')
      ->getStorage('ai_conversation_message')
      ->create([
        'host_entity_type' => 'node',
        'host_entity_id' => 999999,
        'role' => AiConversationMessageInterface::ROLE_USER,
        'content' => 'Hello',
      ]);
    $message->save();

    $viewer = $this->createUser(['access ai conversation message overview']);
    $this->assertTrue($message->access('view', $viewer));

    $noPermission = $this->createUser();
    $this->assertFalse($message->access('view', $noPermission));
  }

}
