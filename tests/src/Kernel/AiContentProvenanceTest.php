<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\AiContentProvenance;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for AI content provenance persistence.
 */
#[Group('oe_ai_assistant')]
class AiContentProvenanceTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests that provenance records can be stored and cleaned up.
   */
  public function testCreateAndSessionCleanup(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_content_provenance');
    $message_storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');

    $user = $this->createUser();
    $session = $this->createSession($user);
    $message = $message_storage->create([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => (int) $session->id(),
      'role' => AiConversationMessageInterface::ROLE_ASSISTANT,
      'agent_id' => 'orchestrator',
      'content' => 'Draft ready.',
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $message->setToolCalls([
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content'],
      ],
    ]);
    $message->save();

    $provenance = AiContentProvenance::create([
      'entity_type' => 'node',
      'entity_id' => 99,
      'revision_id' => 123,
      'uid' => $user->id(),
      'session' => $session->id(),
      'message' => $message->id(),
      'tokens_input' => 4,
      'tokens_output' => 5,
      'tokens_total' => 9,
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $provenance->save();

    $loaded = $storage->loadUnchanged($provenance->id());
    $this->assertNotNull($loaded);
    $this->assertSame('node', $loaded->get('entity_type')->value);
    $this->assertSame(99, (int) $loaded->get('entity_id')->value);
    $this->assertSame(123, (int) $loaded->get('revision_id')->value);
    $this->assertSame((int) $session->id(), (int) $loaded->get('session')->target_id);
    $this->assertSame((int) $message->id(), (int) $loaded->get('message')->target_id);

    $session->delete();

    $reloaded = $storage->loadUnchanged($provenance->id());
    $this->assertNotNull($reloaded);
    $this->assertNull($reloaded->get('session')->target_id);
    $this->assertNull($reloaded->get('message')->target_id);
  }

  /**
   * Tests that deleting a message clears only the provenance message link.
   */
  public function testMessageCleanupKeepsSessionReference(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_content_provenance');
    $message_storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');

    $user = $this->createUser();
    $session = $this->createSession($user);
    $message = $message_storage->create([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => (int) $session->id(),
      'role' => AiConversationMessageInterface::ROLE_ASSISTANT,
      'agent_id' => 'orchestrator',
      'content' => 'Draft ready.',
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $message->setToolCalls([
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content'],
      ],
    ]);
    $message->save();

    $provenance = AiContentProvenance::create([
      'entity_type' => 'node',
      'entity_id' => 101,
      'revision_id' => 456,
      'uid' => $user->id(),
      'session' => $session->id(),
      'message' => $message->id(),
      'tokens_input' => 1,
      'tokens_output' => 2,
      'tokens_total' => 3,
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $provenance->save();

    $message->delete();

    $reloaded = $storage->loadUnchanged($provenance->id());
    $this->assertNotNull($reloaded);
    $this->assertSame((int) $session->id(), (int) $reloaded->get('session')->target_id);
    $this->assertNull($reloaded->get('message')->target_id);
  }

}
