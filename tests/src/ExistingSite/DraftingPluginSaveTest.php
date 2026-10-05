<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;

/**
 * Integration tests for saving a draft through the save_draft tool.
 *
 * A save is a gated tool call: the model asks for it, the run suspends, and the
 * editor approves before anything is written. The call names a draft version,
 * and the backend resolves the field values from its own draft history, so a
 * client never submits field data.
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
   * Tests that a save waits for approval and then writes the named version.
   *
   * Two versions are seeded; approving the call for version 1 must use version
   * 1 fields even though a newer draft exists.
   */
  public function testSaveWaitsForApprovalThenWritesTheNamedVersion(): void {
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

    // The model asks to save version 1, then answers once the tool has run.
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_save',
          'type' => 'function',
          'function' => ['name' => 'save_draft', 'arguments' => '{"version": 1}'],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1.0 is saved.'));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Save Draft 1.0',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    // The turn ends with the request the editor has to answer, and nothing is
    // written until they do.
    $requests = array_values(array_filter(
      $this->parseSseEvents($result['body']),
      fn($event) => $event['type'] === 'data-approval-request',
    ));
    $this->assertCount(1, $requests, 'The suspended run asks for a decision.');
    $approval = $requests[0]['data']['approvals'][0];
    $this->assertSame('call_save', $approval['id']);
    $this->assertSame('save_draft', $approval['name']);
    $this->assertSame(
      'Saving writes an unpublished revision of the content item.',
      $approval['reason'],
      'The approver reads why the tool asked.',
    );
    $this->assertSame(['version' => 1], $approval['inputs']);
    $this->assertNull($this->reloadSession($session)->getNode(),
      'Nothing is written while the call waits for a decision.');

    // The same request survives a reload, because the run is durable.
    $pending = json_decode($this->httpPost('/api/ai/plugins/drafting/get-approvals', [
      'sessionId' => $session->id(),
    ])['body'], TRUE);
    $this->assertSame(['call_save'], array_column($pending['approvals'], 'id'));

    $result = $this->httpPost('/api/ai/plugins/drafting/submit-approval', [
      'sessionId' => $session->id(),
      'callId' => 'call_save',
      'decision' => 'approve',
    ]);
    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    // The node carries the fields of the REQUESTED version, not the latest.
    $node = $this->reloadSession($session)->getNode();
    $this->assertNotNull($node, 'The approved save writes the node.');
    $this->assertEquals('Draft one title', $node->getTitle());
    $this->assertEquals('oe_news', $node->bundle());
    $this->assertEquals('draft', $node->get('moderation_state')->value);
    $this->assertEquals((int) $user->id(), (int) $node->getOwnerId(),
      'Saved node owner must be the current user.');

    // The conversation records which draft was saved and what it produced.
    $saved = $this->resultOfToolCall($session, 'save_draft');
    $this->assertNotNull($saved, 'The save is recorded on the call that asked.');
    $this->assertSame(1, $saved['version']);
    $this->assertSame('Draft 1.0', $saved['name']);
    $this->assertSame((string) $node->id(), $saved['nodeId']);
  }

  /**
   * Tests that a rejected save writes nothing and tells the model why.
   */
  public function testRejectedSaveWritesNothing(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, ['title' => [['value' => 'Draft one title']]]);

    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_save',
          'type' => 'function',
          'function' => ['name' => 'save_draft', 'arguments' => '{"version": 1}'],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'Understood, I will not save it.'));

    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Save Draft 1.0',
      'sessionId' => $session->id(),
    ]);

    $result = $this->httpPost('/api/ai/plugins/drafting/submit-approval', [
      'sessionId' => $session->id(),
      'callId' => 'call_save',
      'decision' => 'reject',
      'reason' => 'The teaser is still wrong.',
    ]);
    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    $this->assertNull($this->reloadSession($session)->getNode(),
      'A rejected save writes no node.');

    // The reason reaches the model, so it can say what was turned down.
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $texts = array_column(end($log)['messages'], 'text');
    $this->assertStringContainsString(
      'The teaser is still wrong.',
      implode(' ', $texts),
      'The rejection reason is sent to the model.',
    );
  }

  /**
   * Tests that a decision naming an unknown call is refused.
   */
  public function testUnknownCallIsRefused(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/submit-approval', [
      'sessionId' => $session->id(),
      'callId' => 'call_nothing',
      'decision' => 'approve',
    ]);
    $this->assertEquals(400, $result['status']);
  }

  /**
   * Reloads a session, so a field written by a request is read back.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The reloaded session.
   */
  private function reloadSession(AiEditorialSessionInterface $session): AiEditorialSessionInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('ai_editorial_session');
    $storage->resetCache([$session->id()]);

    return $storage->load($session->id());
  }

  /**
   * Tests that an answer with a key unknown to the bundle is corrected.
   *
   * When the model answers with "body" instead of "field_body", the drafter
   * rejects the answer against the schema and asks again with the violations.
   * The recorded draft only carries the template's fields, so the save does
   * not fail on a field the entity builder cannot deserialize.
   */
  public function testSaveSurvivesDraftWithFieldUnknownToBundle(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);
    // Pin the template so the drafted field names are deterministic: the
    // news_default template exposes title, field_teaser and field_body in a
    // single main_fields group.
    $session->set('template', 'news_default')->save();

    MockAiProvider::reset();
    // The agent drafts the single group of the template.
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'draft_group', 'arguments' => '{"group":"main_fields"}'],
        ],
      ],
    ));
    // The main_fields drafter ignores the schema and answers with "body",
    // then answers correctly once told what was wrong.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Stray key title"}], "body": [{"value": "<p>Text</p>", "format": "full_html"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Stray key title"}], "field_teaser": [{"value": "Teaser."}], "field_body": [{"value": "<p>Text</p>", "format": "full_html"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1.0 is ready.'));

    $chat = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $chat['status'],
      'Expected 200 from chat. Body: ' . substr($chat['body'], 0, 500));

    // The recorded draft must only contain fields from the template schema.
    /** @var \Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface $history */
    $history = \Drupal::service('Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface');
    $draft = $history->getDraftContent($session, 1);
    $this->assertNotNull($draft, 'Draft 1 must be recorded.');
    $unknown = array_diff(array_keys($draft['fields']), ['title', 'field_teaser', 'field_body']);
    $this->assertSame([], array_values($unknown),
      'The draft must not carry fields outside the template schema.');

    // And the draft must be saveable as a node.
    $saved = $this->approveSave($session, 1);
    $this->assertArrayHasKey('nodeId', $saved, 'The save wrote a node: ' . json_encode($saved));
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($saved['nodeId']);
    $this->assertNotNull($node, 'The created node should exist.');
    $this->assertEquals('Stray key title', $node->getTitle());
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

    $saved = $this->approveSave($session, 1);
    $this->assertArrayHasKey('nodeId', $saved,
      'The save wrote a node: ' . json_encode($saved));
    $node = \Drupal::entityTypeManager()->getStorage('node')
      ->load($saved['nodeId']);
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
    ], ['template' => ['id' => 'news_preview_defaults', 'label' => 'news_preview_defaults']]);

    $saved = $this->approveSave($session, 1);
    $this->assertArrayHasKey('nodeId', $saved,
      'The save wrote a node: ' . json_encode($saved));
    $node = \Drupal::entityTypeManager()->getStorage('node')
      ->load($saved['nodeId']);
    $this->assertNotNull($node, 'Saved node exists.');
    $this->assertEquals('Defaults round-trip', $node->getTitle());
    $this->assertSame('Default teaser from template.',
      $node->get('field_teaser')->value,
      'Template default must be merged into the saved node.');
  }

  /**
   * Tests that saving a version the session never produced is refused.
   */
  public function testSaveUnknownVersionIsRefused(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, [
      'title' => [['value' => 'Only draft']],
    ]);

    $saved = $this->approveSave($session, 99);

    $this->assertArrayNotHasKey('nodeId', $saved, 'Nothing is written.');
    $this->assertStringContainsString(
      'Draft version 99 does not exist in this session.',
      $saved['error'] ?? '',
      'The tool tells the model why it could not save.',
    );
  }

  /**
   * Tests that save without create permission writes nothing.
   *
   * The save runs inside a tool, so the refusal comes back as the tool's
   * answer and the model reports it. The HTTP status belongs to the turn,
   * which succeeded.
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

    $saved = $this->approveSave($session, 1);

    $this->assertArrayNotHasKey('nodeId', $saved, 'Nothing is written.');
    $this->assertArrayHasKey('error', $saved, 'The tool reports the refusal.');
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
    // The second draft revises the first, so it is named "Draft 1.1".
    $this->seedDraft($session, 2, ['title' => [['value' => 'Second save']]], [], 1, 1);

    $firstSave = $this->approveSave($session, 1, 'call_first');
    $secondSave = $this->approveSave($session, 2, 'call_second');
    $this->assertArrayHasKey('nodeId', $secondSave,
      'The second save wrote a node: ' . json_encode($secondSave));

    $this->assertEquals($firstSave['nodeId'], $secondSave['nodeId'],
      'A later save must revise the same node, not create a new one.');

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([(int) $secondSave['nodeId']]);
    /** @var \Drupal\node\NodeInterface $node */
    $node = $storage->load($secondSave['nodeId']);
    $this->assertEquals('Second save', $node->getTitle(), 'The latest revision carries the second draft.');
    $this->assertEquals('draft', $node->get('moderation_state')->value);
    $this->assertStringContainsString(
      sprintf('Draft 1.1 from session %s', $session->label()),
      $node->getRevisionLogMessage(),
    );

    $revisionIds = \Drupal::entityTypeManager()->getStorage('node')
      ->getQuery()
      ->allRevisions()
      ->condition('nid', $secondSave['nodeId'])
      ->accessCheck(FALSE)
      ->execute();
    $this->assertGreaterThanOrEqual(2, count($revisionIds), 'The second save must add a new revision.');

    // The session's node reference stays on the same node.
    $sessionStorage = \Drupal::entityTypeManager()->getStorage('ai_editorial_session');
    $sessionStorage->resetCache([$session->id()]);
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $reloaded */
    $reloaded = $sessionStorage->load($session->id());
    $this->assertEquals($firstSave['nodeId'], $reloaded->getNode()->id());
  }

  /**
   * Tests that a later save without node update access returns 403.
   */
  public function testReviseSaveWithoutUpdateAccessIsRefused(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedDraft($session, 1, ['title' => [['value' => 'First save']]]);
    // The second draft revises the first, so it is named "Draft 1.1".
    $this->seedDraft($session, 2, ['title' => [['value' => 'Second save']]], [], 1, 1);

    $this->approveSave($session, 1, 'call_first');

    // The user has no edit permission on the node the first save created,
    // so the second, revision-adding save must be refused.
    $second = $this->approveSave($session, 2, 'call_second');

    $this->assertArrayNotHasKey('nodeId', $second, 'No revision is written.');
    $this->assertArrayHasKey('error', $second, 'The tool reports the refusal.');
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

    $firstSave = $this->approveSave($session, 1, 'call_first');

    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
    $nodeStorage->delete([$nodeStorage->load($firstSave['nodeId'])]);

    $secondSave = $this->approveSave($session, 2, 'call_second');
    $this->assertArrayHasKey('nodeId', $secondSave,
      'The fallback save wrote a node: ' . json_encode($secondSave));

    $this->assertNotEquals($firstSave['nodeId'], $secondSave['nodeId'],
      'A fresh node must be created once the referenced one is gone.');
    $node = $nodeStorage->load($secondSave['nodeId']);
    $this->assertNotNull($node, 'The fallback node exists.');
    $this->assertEquals('After deletion', $node->getTitle());

    $sessionStorage = \Drupal::entityTypeManager()->getStorage('ai_editorial_session');
    $sessionStorage->resetCache([$session->id()]);
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $reloaded */
    $reloaded = $sessionStorage->load($session->id());
    $this->assertEquals($secondSave['nodeId'], $reloaded->getNode()->id(),
      'The session must repoint to the newly created node.');
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
