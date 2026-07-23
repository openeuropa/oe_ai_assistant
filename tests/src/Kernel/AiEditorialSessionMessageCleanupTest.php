<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\AiConversationMessage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSession;
use Drupal\Tests\oe_ai_assistant\Traits\AiConversationMessageTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the conversation cleanup on session deletion.
 *
 * Deleting a session removes every message it hosts, while any other lifecycle
 * change leaves the conversation alone.
 */
#[Group('oe_ai_assistant')]
class AiEditorialSessionMessageCleanupTest extends AiEditorialSessionKernelTestBase {

  use AiConversationMessageTrait;

  /**
   * Tests that deleting a session removes the conversation it hosts.
   */
  public function testSessionDeleteRemovesItsMessages(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $user = $this->createUser();
    $session = $this->createSession($user);

    // The whole conversation shape: top-level turns, a sub-agent child and an
    // error row. All of them belong to the session and must go with it.
    $ids = [];
    $ids[] = (int) $this->createMessage($session, AiConversationMessageInterface::ROLE_USER, 'Draft a news article.')->id();
    $assistant = $this->createMessage($session, AiConversationMessageInterface::ROLE_ASSISTANT, 'Here is the plan.');
    $ids[] = (int) $assistant->id();
    $ids[] = (int) $this->createMessage($session, AiConversationMessageInterface::ROLE_TOOL, 'Tool result.', (int) $assistant->id())->id();
    $ids[] = (int) $this->createMessage($session, AiConversationMessageInterface::ROLE_ERROR, 'Boom.')->id();

    // Another session's conversation must survive.
    $other_session = $this->createSession($user);
    $other_session_message = $this->createMessage($other_session, AiConversationMessageInterface::ROLE_USER, 'Other session turn.');

    // A message hosted by a different entity type but sharing the session id
    // must survive too: the cleanup is scoped by host type as well as host id.
    $other_host_message = AiConversationMessage::create([
      'host_entity_type' => 'node',
      'host_entity_id' => (int) $session->id(),
      'role' => AiConversationMessageInterface::ROLE_USER,
      'content' => 'Node-hosted turn.',
    ]);
    $other_host_message->save();

    $session->delete();

    foreach ($ids as $id) {
      $this->assertNull($storage->loadUnchanged($id), sprintf('Message %d was deleted with its session.', $id));
    }

    // Nothing beyond the session's own conversation was touched.
    $remaining = $storage->getQuery()->accessCheck(FALSE)->execute();
    $this->assertSame([
      (int) $other_session_message->id(),
      (int) $other_host_message->id(),
    ], array_map('intval', array_values($remaining)));
  }

  /**
   * Tests that completing a session leaves its conversation intact.
   */
  public function testCompletingSessionKeepsMessages(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $user = $this->createUser();
    $session = $this->createSession($user);

    $question = $this->createMessage($session, AiConversationMessageInterface::ROLE_USER, 'Draft a news article.');
    $answer = $this->createMessage($session, AiConversationMessageInterface::ROLE_ASSISTANT, 'Here is the plan.');

    $session->setStatus(AiEditorialSession::STATUS_COMPLETED);
    $session->save();

    $reloaded = AiEditorialSession::load($session->id());
    $this->assertSame(AiEditorialSession::STATUS_COMPLETED, $reloaded?->getStatus());

    $this->assertNotNull($storage->loadUnchanged((int) $question->id()));
    $this->assertNotNull($storage->loadUnchanged((int) $answer->id()));
  }

  /**
   * Tests that deleting a session without a conversation is a no-op.
   */
  public function testDeletingSessionWithoutMessagesIsSafe(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $user = $this->createUser();
    $empty_session = $this->createSession($user);

    $other_session = $this->createSession($user);
    $message = $this->createMessage($other_session, AiConversationMessageInterface::ROLE_USER, 'Draft a news article.');

    $empty_session->delete();

    $this->assertNull(AiEditorialSession::load($empty_session->id()));
    $this->assertNotNull($storage->loadUnchanged((int) $message->id()));
  }

}
