<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\oe_ai_assistant\Entity\AiContentProvenance;
use Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiContentProvenanceStorageInterface;
use Drupal\Tests\oe_ai_assistant\Traits\AiConversationMessageTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the ai_content_provenance entity and its storage handler.
 */
#[Group('oe_ai_assistant')]
class AiContentProvenanceTest extends AiEditorialSessionKernelTestBase {

  use AiConversationMessageTrait;

  /**
   * Tests the revision lookups.
   */
  public function testRevisionLookups(): void {
    $user = $this->createUser();
    $session = $this->createSession($user);
    $message = $this->createDraftTurn($session);
    $record = $this->createRecord(99, 123, $user->id(), $session, $message);
    $this->createRecord(99, 123, $user->id(), $session, $message, 'link_list');

    $this->assertSame((int) $record->id(), (int) $this->storage()->loadForRevision('node', 99, 123)?->id());
    $this->assertSame([123], array_keys($this->storage()->loadForRevisions('node', 99, [122, 123, 124])));
    $this->assertSame('node 99 revision 123', $record->label());
  }

  /**
   * Tests that the database rejects a second record for the same revision.
   */
  public function testUniqueRevisionKey(): void {
    $user = $this->createUser();
    $session = $this->createSession($user);
    $message = $this->createDraftTurn($session);
    $this->createRecord(99, 123, $user->id(), $session, $message);

    $this->expectException(EntityStorageException::class);
    $this->createRecord(99, 123, $user->id(), $session, $message);
  }

  /**
   * Tests that deleting a session or a message clears the references.
   */
  public function testReferenceClearing(): void {
    $user = $this->createUser();
    $session = $this->createSession($user);
    $message = $this->createDraftTurn($session);
    $record = $this->createRecord(99, 123, $user->id(), $session, $message);

    $message->delete();
    $reloaded = $this->storage()->loadUnchanged($record->id());
    $this->assertSame((int) $session->id(), (int) $reloaded->getSession()?->id());
    $this->assertNull($reloaded->getMessage());

    $session->delete();
    $reloaded = $this->storage()->loadUnchanged($record->id());
    $this->assertNull($reloaded->getSession());
    $this->assertSame(['input' => 4, 'output' => 5, 'total' => 9], $reloaded->getTokenUsage());
  }

  /**
   * Tests that deleting a tracked revision or entity removes its records.
   */
  public function testTrackedDeletion(): void {
    $user = $this->createUser();
    $session = $this->createSession($user);
    $message = $this->createDraftTurn($session);

    $node = Node::create(['type' => 'oe_news', 'title' => 'Tracked', 'uid' => $user->id()]);
    $node->save();
    $first_vid = (int) $node->getRevisionId();
    $node->setNewRevision();
    $node->save();
    $first = $this->createRecord((int) $node->id(), $first_vid, $user->id(), $session, $message);
    $second = $this->createRecord((int) $node->id(), (int) $node->getRevisionId(), $user->id(), $session, $message);

    $this->container->get('entity_type.manager')->getStorage('node')->deleteRevision($first_vid);
    $this->assertNull($this->storage()->loadUnchanged($first->id()));

    $node->delete();
    $this->assertNull($this->storage()->loadUnchanged($second->id()));
  }

  /**
   * Tests the access handler.
   */
  public function testAccess(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $record = $this->createRecord(99, 123, $owner->id(), $session, $this->createDraftTurn($session));

    $this->assertTrue($record->access('view', $this->createUser(['view ai content provenance'])));
    $this->assertTrue($record->access('delete', $this->createUser(['administer ai content provenance'])));
  }

  /**
   * Creates a provenance record directly.
   */
  private function createRecord(int $entity_id, int $revision_id, int|string $uid, AiEditorialSessionInterface $session, AiConversationMessageInterface $message, string $entity_type = 'node'): AiContentProvenanceInterface {
    $record = AiContentProvenance::create([
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'revision_id' => $revision_id,
      'uid' => $uid,
      'session' => $session->id(),
      'message' => $message->id(),
      'tokens_input' => 4,
      'tokens_output' => 5,
      'tokens_total' => 9,
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $record->save();
    return $record;
  }

  /**
   * Returns the provenance storage handler.
   */
  private function storage(): AiContentProvenanceStorageInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_content_provenance');
    assert($storage instanceof AiContentProvenanceStorageInterface);
    return $storage;
  }

}
