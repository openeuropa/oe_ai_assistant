<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\DraftSaverInterface;
use Drupal\user\UserInterface;

/**
 * Integration tests for the draft saver service.
 *
 * Calls the saver as the current user, with the fields and template the
 * session recorded for a draft version.
 */
class DraftSaverTest extends DraftingPluginTestBase {

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
   * Tests that the saver writes the fields of the named draft version.
   *
   * Two versions are seeded; saving version 1 must use version 1 fields
   * even though a newer draft exists.
   */
  public function testSaveCreatesNodeFromDraftVersion(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $session = $this->createSession($user);
    $this->seedDraft($session, 1, ['title' => [['value' => 'Draft one title']]]);
    $this->seedDraft($session, 2, ['title' => [['value' => 'Draft two title']]]);

    $result = $this->save($user, $session, 1);

    $this->assertArrayHasKey('nodeId', $result);
    $this->assertArrayHasKey('previewUrl', $result);
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($result['nodeId']);
    $this->assertNotNull($node, 'The created node should exist.');
    $this->assertEquals('Draft one title', $node->getTitle());
    $this->assertEquals('oe_news', $node->bundle());
    $this->assertEquals('draft', $node->get('moderation_state')->value);
    $this->assertEquals((int) $user->id(), (int) $node->getOwnerId(),
      'Saved node owner must be the current user.');

    $reloaded = $this->reloadSession($session);
    $this->assertNotNull($reloaded->getNode(), 'The session must reference the saved node.');
    $this->assertEquals($result['nodeId'], $reloaded->getNode()->id());
  }

  /**
   * Tests that the saver creates a node with inline paragraphs.
   *
   * Core 11.3.x silently drops inline children (see
   * `CoreJsonSchemaTest::testDeserializeSilentlyDropsInlineParagraphs`), so
   * the hydrator handles paragraph creation while the parent goes through
   * plain `$serializer->deserialize()`.
   */
  public function testSaveCreatesNodeWithParagraphs(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
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

    $result = $this->save($user, $session, 1);

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($result['nodeId']);
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
    $session = $this->createSession($user);
    $this->seedDraft($session, 1, [
      'title' => [['value' => 'Defaults round-trip']],
    ], 'news_preview_defaults');

    $result = $this->save($user, $session, 1);

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($result['nodeId']);
    $this->assertNotNull($node, 'Saved node exists.');
    $this->assertEquals('Defaults round-trip', $node->getTitle());
    $this->assertSame('Default teaser from template.',
      $node->get('field_teaser')->value,
      'Template default must be merged into the saved node.');
  }

  /**
   * Tests that saving without create permission is forbidden.
   */
  public function testSavePermissionDenied(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $session = $this->createSession($user);
    $this->seedDraft($session, 1, ['title' => [['value' => 'Fail']]]);

    $this->assertForbidden(fn () => $this->save($user, $session, 1));
  }

  /**
   * Tests that a second save adds a revision to the session's node.
   *
   * The session owns at most one node: the first save creates it, every
   * later save adds a new revision instead of a fresh node.
   */
  public function testSecondSaveAddsRevisionToSameNode(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
      'edit own oe_news content',
      'use editorial transition create_new_draft',
    ]);
    $session = $this->createSession($user);
    $this->seedDraft($session, 1, ['title' => [['value' => 'First save']]]);
    $this->seedDraft($session, 2, ['title' => [['value' => 'Second save']]]);

    $first = $this->save($user, $session, 1);
    $second = $this->save($user, $session, 2);

    $this->assertEquals($first['nodeId'], $second['nodeId'],
      'A later save must revise the same node, not create a new one.');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([(int) $second['nodeId']]);
    /** @var \Drupal\node\NodeInterface $node */
    $node = $storage->load($second['nodeId']);
    $this->assertEquals('Second save', $node->getTitle(), 'The latest revision carries the second draft.');
    $this->assertEquals('draft', $node->get('moderation_state')->value);
    $this->assertStringContainsString(
      sprintf('Draft 2 from session %s', $session->label()),
      $node->getRevisionLogMessage(),
    );
    $revisionIds = $storage->getQuery()
      ->allRevisions()
      ->condition('nid', $second['nodeId'])
      ->accessCheck(FALSE)
      ->execute();
    $this->assertGreaterThanOrEqual(2, count($revisionIds), 'The second save must add a new revision.');
    $this->assertEquals($first['nodeId'], $this->reloadSession($session)->getNode()->id());
  }

  /**
   * Tests that a later save without node update access is forbidden.
   */
  public function testReviseSaveWithoutUpdateAccessIsForbidden(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $session = $this->createSession($user);
    $this->seedDraft($session, 1, ['title' => [['value' => 'First save']]]);
    $this->seedDraft($session, 2, ['title' => [['value' => 'Second save']]]);
    $this->save($user, $session, 1);

    // The user has no edit permission on the node the first save created,
    // so the second, revision-adding save must be denied.
    $this->assertForbidden(fn () => $this->save($user, $session, 2));
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
    $session = $this->createSession($user);
    $this->seedDraft($session, 1, ['title' => [['value' => 'First save']]]);
    $this->seedDraft($session, 2, ['title' => [['value' => 'After deletion']]]);
    $first = $this->save($user, $session, 1);
    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
    $nodeStorage->delete([$nodeStorage->load($first['nodeId'])]);

    $second = $this->save($user, $session, 2);

    $this->assertNotEquals($first['nodeId'], $second['nodeId'],
      'A fresh node must be created once the referenced one is gone.');
    $node = $nodeStorage->load($second['nodeId']);
    $this->assertNotNull($node, 'The fallback node exists.');
    $this->assertEquals('After deletion', $node->getTitle());
    $this->assertEquals($second['nodeId'], $this->reloadSession($session)->getNode()->id(),
      'The session must repoint to the newly created node.');
  }

  /**
   * Saves a draft version of the session as the user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user who saves.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session holding the draft.
   * @param int $version
   *   The draft version to save.
   *
   * @return array
   *   The saver's result.
   */
  private function save(UserInterface $user, AiEditorialSessionInterface $session, int $version): array {
    $this->setCurrentUser($user);
    $draft = $session->getDraftContent($version);
    $this->assertNotNull($draft, sprintf('Draft %d is seeded.', $version));

    return \Drupal::service(DraftSaverInterface::class)
      ->save($session, $draft['fields'], $draft['templateId'], $version);
  }

  /**
   * Asserts that the callback is refused with a 403 forbidden error.
   */
  private function assertForbidden(callable $callback): void {
    try {
      $callback();
      $this->fail('The save should be forbidden.');
    }
    catch (ActionException $e) {
      $this->assertSame(403, $e->statusCode);
      $this->assertSame('forbidden', $e->errorCode);
    }
  }

  /**
   * Loads the session again from storage.
   */
  private function reloadSession(AiEditorialSessionInterface $session): AiEditorialSessionInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('ai_editorial_session');
    $storage->resetCache([$session->id()]);

    return $storage->load($session->id());
  }

}
