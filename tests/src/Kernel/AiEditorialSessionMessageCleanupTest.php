<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Document\ContextDocumentStorage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSession;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules(['oe_ai_assistant_test']);
    $this->installSchema('file', ['file_usage']);
    $this->config('ai.settings')
      ->set('default_providers.chat_with_image_vision', [
        'provider_id' => 'mock_ai',
        'model_id' => 'mock-model',
      ])
      ->save();
    MockAiProvider::reset();
  }

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

    // Session creation records an initial-state event row that survives
    // together with the rest of the other session's conversation.
    $query_result = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('host_entity_type', $other_session->getEntityTypeId())
      ->condition('host_entity_id', (int) $other_session->id())
      ->execute();
    $other_session_event_id = (int) reset($query_result);

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
      $other_session_event_id,
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

  /**
   * Tests that deleting a session removes its private context documents.
   */
  public function testSessionDeleteRemovesItsContextDocuments(): void {
    $media_storage = $this->container->get('entity_type.manager')->getStorage('media');
    $file_storage = $this->container->get('entity_type.manager')->getStorage('file');
    $user = $this->createUser();
    $session = $this->createSession($user);
    [$media, $file] = $this->createContextDocument('brief.txt');

    $session->get(ContextDocumentStorage::SESSION_FIELD)->appendItem([
      'target_id' => $media->id(),
    ]);
    $session->save();

    $session->delete();

    $this->assertNull($media_storage->loadUnchanged((int) $media->id()));
    $this->assertNull($file_storage->loadUnchanged((int) $file->id()));
  }

  /**
   * Tests that shared context documents survive until the last session is gone.
   */
  public function testSharedContextDocumentSurvivesSessionDelete(): void {
    $media_storage = $this->container->get('entity_type.manager')->getStorage('media');
    $file_storage = $this->container->get('entity_type.manager')->getStorage('file');
    $user = $this->createUser();
    $session = $this->createSession($user);
    $other_session = $this->createSession($user);
    [$media, $file] = $this->createContextDocument('shared-brief.txt');

    $this->attachContextDocument($session, $media);
    $this->attachContextDocument($other_session, $media);

    $session->delete();

    $this->assertNotNull($media_storage->loadUnchanged((int) $media->id()));
    $this->assertNotNull($file_storage->loadUnchanged((int) $file->id()));

    $other_session->delete();

    $this->assertNull($media_storage->loadUnchanged((int) $media->id()));
    $this->assertNull($file_storage->loadUnchanged((int) $file->id()));
  }

  /**
   * Creates a private context document media item.
   *
   * @return array{
   *   0: \Drupal\media\MediaInterface,
   *   1: \Drupal\file\FileInterface
   *   }
   *   The created media and file entities.
   */
  private function createContextDocument(string $filename): array {
    MockAiProvider::enqueue(new MockResponse('Summary for ' . $filename));
    $file = $this->container->get('file.repository')->writeData(
      'Context document contents.',
      'public://' . $filename,
      FileExists::Rename,
    );
    assert($file instanceof FileInterface);
    $file->setPermanent();
    $file->save();

    $media = Media::create([
      'bundle' => ContextDocumentStorage::MEDIA_BUNDLE,
      'name' => $filename,
      'status' => 0,
      ContextDocumentStorage::SOURCE_FIELD => [
        'target_id' => $file->id(),
      ],
    ]);
    $media->save();

    return [$media, $file];
  }

  /**
   * Attaches a context document to a session.
   */
  private function attachContextDocument(AiEditorialSessionInterface $session, MediaInterface $media): void {
    $session->get(ContextDocumentStorage::SESSION_FIELD)->appendItem([
      'target_id' => $media->id(),
    ]);
    $session->save();
  }

}
