<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Integration tests for the DraftingPlugin save action.
 *
 * Sends real HTTP POST requests to /api/ai/plugins/drafting/save and verifies
 * the responses and created entities. The request names a session and a draft
 * version; the backend resolves the drafted field values from its own draft
 * history, so clients never submit field data.
 */
class DraftingPluginSaveTest extends DraftingPluginTestBase {

  /**
   * The IDs of existing entities before the test, keyed by entity type.
   *
   * @var array
   */
  protected $existingEntityIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->trackEntityType('node');
    $this->trackEntityType('paragraph');
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown(): void {
    $this->deleteTestEntities();
    parent::tearDown();
  }

  /**
   * Tests that save resolves the named draft version from the history.
   *
   * Two versions are seeded; saving version 1 must use version 1 fields even
   * though a newer draft exists, and the save is recorded as a durable
   * timeline event.
   */
  public function testSaveCreatesNodeFromDraftVersion(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, [
      'title' => [['value' => 'Draft one title']],
    ]);
    $this->seedDraft($session, 2, [
      'title' => [['value' => 'Draft two title']],
    ]);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . substr($result['body'], 0, 500));
    $body = json_decode($result['body'], TRUE);
    $this->assertArrayHasKey('nodeId', $body);
    $this->assertArrayHasKey('previewUrl', $body);

    // The node carries the fields of the REQUESTED version, not the latest.
    $node = \Drupal::entityTypeManager()->getStorage('node')
      ->load($body['nodeId']);
    $this->assertNotNull($node, 'The created node should exist.');
    $this->assertEquals('Draft one title', $node->getTitle());
    $this->assertEquals('oe_news', $node->bundle());
    $this->assertEquals('draft', $node->get('moderation_state')->value);
    // Owner must be the current user, set explicitly post-deserialize.
    $this->assertEquals((int) $user->id(), (int) $node->getOwnerId(),
      'Saved node owner must be the current user.');

    // The save flow writes the created node back onto the session.
    $storage = \Drupal::entityTypeManager()->getStorage('ai_editorial_session');
    $storage->resetCache([$session->id()]);
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $reloaded */
    $reloaded = $storage->load($session->id());
    $this->assertNotNull($reloaded->getNode(), 'The session must reference the saved node.');
    $this->assertEquals($body['nodeId'], $reloaded->getNode()->id());

    // The save is recorded as a durable timeline event on the transcript.
    $events = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] === 'event' && $m['type'] === 'save',
    ));
    $this->assertCount(1, $events, 'The save must record one event row.');
    $this->assertStringContainsString('Draft 1', $events[0]['summary']);
  }

  /**
   * Tests that save creates a node with inline paragraphs.
   *
   * End-to-end exercise of the deserialize-paragraph path through
   * `InlineEntityHydrator`. Core 11.3.x silently drops inline children (see
   * `CoreJsonSchemaTest::testDeserializeSilentlyDropsInlineParagraphs`), so
   * the hydrator handles paragraph creation while the parent goes through
   * plain `$serializer->deserialize()`.
   */
  public function testSaveCreatesNodeWithParagraphs(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, [
      'title' => [['value' => 'Paragraph round-trip']],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'text_block']],
          'field_text_body' => [['value' => 'First paragraph.']],
        ],
        [
          'type' => [['target_id' => 'quote_block']],
          'field_quote_text' => [['value' => 'A wise quote.']],
          'field_quote_attribution' => [['value' => 'Anon']],
        ],
      ],
    ]);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . substr($result['body'], 0, 500));
    $body = json_decode($result['body'], TRUE);
    $node = \Drupal::entityTypeManager()->getStorage('node')
      ->load($body['nodeId']);
    $this->assertNotNull($node, 'Saved node exists.');
    $this->assertEquals('Paragraph round-trip', $node->getTitle());

    $paragraphs = $node->get('field_content_paragraphs')->referencedEntities();
    $this->assertCount(2, $paragraphs, 'Both inline paragraphs were created.');
    $this->assertSame('text_block', $paragraphs[0]->bundle());
    $this->assertSame('First paragraph.', $paragraphs[0]->get('field_text_body')->value);
    $this->assertSame('quote_block', $paragraphs[1]->bundle());
    $this->assertSame('A wise quote.', $paragraphs[1]->get('field_quote_text')->value);
    $this->assertSame('Anon', $paragraphs[1]->get('field_quote_attribution')->value);
  }

  /**
   * Tests that the snapshot template's defaults land on the saved node.
   *
   * The field_teaser value is absent from the drafted fields but supplied by
   * the news_preview_defaults template's defaults, so the saved node must
   * carry it. Keeps save aligned with preview, which merges the same defaults.
   */
  public function testSaveMergesTemplateDefaults(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, [
      'title' => [['value' => 'Defaults round-trip']],
    ], 'news_preview_defaults');

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . substr($result['body'], 0, 500));
    $body = json_decode($result['body'], TRUE);
    $node = \Drupal::entityTypeManager()->getStorage('node')
      ->load($body['nodeId']);
    $this->assertNotNull($node, 'Saved node exists.');
    $this->assertEquals('Defaults round-trip', $node->getTitle());
    $this->assertSame('Default teaser from template.',
      $node->get('field_teaser')->value,
      'Template default must be merged into the saved node.');
  }

  /**
   * Tests that saving a version the session never produced returns 400.
   */
  public function testSaveUnknownVersionReturns400(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, [
      'title' => [['value' => 'Only draft']],
    ]);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 99,
    ]);

    $this->assertEquals(400, $result['status']);
    $body = json_decode($result['body'], TRUE);
    $this->assertEquals('invalid_request', $body['code']);
  }

  /**
   * Tests that save without create permission returns 403.
   */
  public function testSavePermissionDenied(): void {
    $user = $this->createUser([
      'use oe ai assistant',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, [
      'title' => [['value' => 'Fail']],
    ]);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(403, $result['status']);
    $body = json_decode($result['body'], TRUE);
    $this->assertEquals('forbidden', $body['code']);
  }

  /**
   * Tests that a second save adds a revision to the session's node.
   *
   * The session owns at most one node: the first save creates it, every
   * later explicit save adds a new revision instead of a fresh node.
   */
  public function testSecondSaveAddsRevisionToSameNode(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
      'edit own oe_news content',
      'use editorial transition create_new_draft',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, ['title' => [['value' => 'First save']]]);
    $this->seedDraft($session, 2, ['title' => [['value' => 'Second save']]]);

    $first = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);
    $this->assertEquals(200, $first['status']);
    $firstBody = json_decode($first['body'], TRUE);

    $second = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 2,
    ]);
    $this->assertEquals(200, $second['status'],
      'Expected 200 response. Body: ' . substr($second['body'], 0, 500));
    $secondBody = json_decode($second['body'], TRUE);

    $this->assertEquals($firstBody['nodeId'], $secondBody['nodeId'],
      'A later save must revise the same node, not create a new one.');

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([(int) $secondBody['nodeId']]);
    /** @var \Drupal\node\NodeInterface $node */
    $node = $storage->load($secondBody['nodeId']);
    $this->assertEquals('Second save', $node->getTitle(), 'The latest revision carries the second draft.');
    $this->assertEquals('draft', $node->get('moderation_state')->value);
    $this->assertStringContainsString(
      sprintf('Draft 2 from session %s', $session->label()),
      $node->getRevisionLogMessage(),
    );

    $revisionIds = \Drupal::entityTypeManager()->getStorage('node')
      ->getQuery()
      ->allRevisions()
      ->condition('nid', $secondBody['nodeId'])
      ->accessCheck(FALSE)
      ->execute();
    $this->assertGreaterThanOrEqual(2, count($revisionIds), 'The second save must add a new revision.');

    // The session's node reference stays on the same node.
    $sessionStorage = \Drupal::entityTypeManager()->getStorage('ai_editorial_session');
    $sessionStorage->resetCache([$session->id()]);
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $reloaded */
    $reloaded = $sessionStorage->load($session->id());
    $this->assertEquals($firstBody['nodeId'], $reloaded->getNode()->id());
  }

  /**
   * Tests that a later save without node update access returns 403.
   */
  public function testReviseSaveWithoutUpdateAccessReturns403(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, ['title' => [['value' => 'First save']]]);
    $this->seedDraft($session, 2, ['title' => [['value' => 'Second save']]]);

    $first = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);
    $this->assertEquals(200, $first['status']);

    // The user has no edit permission on the node the first save created,
    // so the second, revision-adding save must be denied.
    $second = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 2,
    ]);

    $this->assertEquals(403, $second['status']);
    $body = json_decode($second['body'], TRUE);
    $this->assertEquals('forbidden', $body['code']);
  }

  /**
   * Tests the fallback when the referenced node was deleted.
   *
   * The save must create a fresh node and repoint the session, instead of
   * failing.
   */
  public function testSaveAfterNodeDeletedFallsBackToCreatingNewNode(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, ['title' => [['value' => 'First save']]]);
    $this->seedDraft($session, 2, ['title' => [['value' => 'After deletion']]]);

    $first = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);
    $this->assertEquals(200, $first['status']);
    $firstBody = json_decode($first['body'], TRUE);

    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
    $nodeStorage->delete([$nodeStorage->load($firstBody['nodeId'])]);

    $second = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 2,
    ]);
    $this->assertEquals(200, $second['status'],
      'Expected 200 response. Body: ' . substr($second['body'], 0, 500));
    $secondBody = json_decode($second['body'], TRUE);

    $this->assertNotEquals($firstBody['nodeId'], $secondBody['nodeId'],
      'A fresh node must be created once the referenced one is gone.');
    $node = $nodeStorage->load($secondBody['nodeId']);
    $this->assertNotNull($node, 'The fallback node exists.');
    $this->assertEquals('After deletion', $node->getTitle());

    $sessionStorage = \Drupal::entityTypeManager()->getStorage('ai_editorial_session');
    $sessionStorage->resetCache([$session->id()]);
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $reloaded */
    $reloaded = $sessionStorage->load($session->id());
    $this->assertEquals($secondBody['nodeId'], $reloaded->getNode()->id(),
      'The session must repoint to the newly created node.');
  }

  /**
   * Seeds a completed draft version into the session's transcript.
   *
   * Mirrors how the chat flow records drafts: an assistant turn carrying a
   * draft_content tool call whose result holds the versioned fields.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param int $version
   *   The draft version number.
   * @param array $fields
   *   The drafted field values, keyed by field machine name.
   * @param string|null $templateId
   *   The template id to snapshot in the result context, or NULL for none.
   */
  protected function seedDraft(AiEditorialSessionInterface $session, int $version, array $fields, ?string $templateId = NULL): void {
    $this->seedMessage($session, 'assistant', '', [
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content', 'arguments' => '{}'],
        'result' => [
          'version' => $version,
          'context' => $templateId !== NULL
            ? ['template' => ['id' => $templateId, 'label' => $templateId]]
            : NULL,
          'fields' => $fields,
        ],
      ],
    ]);
  }

  /**
   * Records existing entity IDs so test-created entities can be cleaned up.
   */
  protected function trackEntityType(string $entityType): void {
    $this->existingEntityIds[$entityType] = \Drupal::entityTypeManager()
      ->getStorage($entityType)
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * Deletes entities created during the test.
   */
  protected function deleteTestEntities(): void {
    foreach ($this->existingEntityIds as $entityType => $previousIds) {
      $storage = \Drupal::entityTypeManager()->getStorage($entityType);
      $currentIds = $storage->getQuery()->accessCheck(FALSE)->execute();
      $newIds = array_diff($currentIds, $previousIds);
      if ($newIds) {
        $storage->delete($storage->loadMultiple($newIds));
      }
    }
  }

}
