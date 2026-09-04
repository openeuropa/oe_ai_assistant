<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\node\Entity\Node;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for the DraftingPlugin save action.
 *
 * Sends real HTTP POST requests to /api/ai/plugins/drafting/save and verifies
 * the responses and created entities.
 */
class DraftingPluginSaveTest extends ExistingSiteBase {

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
    $this->trackEntityType('ai_content_provenance');
    $this->trackEntityType('ai_editorial_session');
    $this->trackEntityType('ai_conversation_message');
  }

  /**
   * Tests that save creates a node with simple fields in the new payload shape.
   */
  public function testSaveCreatesNodeWithSimpleFields(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->drupalLogin($user);

    $context = $this->prepareDraftContext($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'sessionId' => $context['session']->id(),
      'fields' => [
        'title' => [['value' => 'Test Save']],
      ],
    ]);

    $this->assertEquals(200, $result['status'], 'Expected 200 response. Body: ' . json_encode($result['body']));
    $this->assertArrayHasKey('nodeId', $result['body']);
    $this->assertArrayHasKey('previewUrl', $result['body']);

    // Verify the node exists and has the correct values.
    $nodeId = $result['body']['nodeId'];
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nodeId);
    $this->assertNotNull($node, 'The created node should exist.');
    $this->assertEquals('Test Save', $node->getTitle());
    $this->assertEquals('oe_news', $node->bundle());
    $this->assertEquals('draft', $node->get('moderation_state')->value);
    // Owner must be the current user, set explicitly post-deserialize.
    $this->assertEquals((int) $user->id(), (int) $node->getOwnerId(),
      'Saved node owner must be the current user.');

    $provenance = \Drupal::entityTypeManager()->getStorage('ai_content_provenance')->loadByProperties([
      'entity_type' => 'node',
      'entity_id' => $nodeId,
      'revision_id' => $node->getRevisionId(),
    ]);
    $this->assertNotEmpty($provenance, 'A provenance record should be created.');
    $provenance = reset($provenance);
    $this->assertSame((int) $context['session']->id(), (int) $provenance->getSession()?->id());
    $this->assertSame((int) $context['assistant']->id(), (int) $provenance->getMessage()?->id());
    $this->assertSame($context['template']->id(), $provenance->getTemplateId());
    $this->assertSame((int) $user->id(), (int) $provenance->getOwnerId());
    $this->assertSame(['input' => 3, 'output' => 4, 'total' => 7], $provenance->getTokenUsage());
    $this->assertSame('mock', $provenance->getProvider());
    $this->assertSame('mock-model', $provenance->getModel());
    $expected_version = ['major' => NULL, 'minor' => NULL, 'patch' => NULL];
    if ($node->hasField('version')) {
      $version = $node->get('version')->first()->getValue();
      $expected_version = [
        'major' => (int) $version['major'],
        'minor' => (int) $version['minor'],
        'patch' => (int) $version['patch'],
      ];
    }
    $this->assertSame($expected_version, $provenance->getVersion());
  }

  /**
   * Tests that only AI-assisted revisions are tracked and queryable.
   */
  public function testManualSaveIsNotTrackedAndQueryReturnsAiRevisions(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->drupalLogin($user);
    $context = $this->prepareDraftContext($user);
    $storage = \Drupal::entityTypeManager()->getStorage('ai_content_provenance');
    $before = $storage->getQuery()->accessCheck(FALSE)->condition('entity_type', 'node')->execute();

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'sessionId' => $context['session']->id(),
      'fields' => ['title' => [['value' => 'AI draft']]],
    ]);
    $this->assertEquals(200, $result['status'], 'Expected 200 response. Body: ' . json_encode($result['body']));
    $manual = Node::create(['type' => 'oe_news', 'title' => 'Manual draft', 'uid' => $user->id()]);
    $manual->save();

    $after = $storage->getQuery()->accessCheck(FALSE)->condition('entity_type', 'node')->execute();
    $records = $storage->loadMultiple(array_diff($after, $before));
    $this->assertCount(1, $records);
    $this->assertSame((int) $result['body']['nodeId'], reset($records)->getTrackedEntityId());
  }

  /**
   * Tests that save can target a specific draft turn by version.
   */
  public function testSaveUsesRequestedDraftVersion(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->drupalLogin($user);

    $context = $this->prepareDraftContext($user);
    $message_storage = \Drupal::entityTypeManager()->getStorage('ai_conversation_message');

    $second_assistant = $message_storage->create([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => (int) $context['session']->id(),
      'role' => 'assistant',
      'agent_id' => 'orchestrator',
      'content' => 'Draft ready again.',
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $second_assistant->setToolCalls([
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content', 'arguments' => '{}'],
      ],
    ]);
    $second_assistant->save();

    $second_child = $message_storage->create([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => (int) $context['session']->id(),
      'parent' => (int) $second_assistant->id(),
      'role' => 'assistant',
      'agent_id' => 'second-title-agent',
      'content' => 'Second title slice.',
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $second_child->setTokenUsage(['input' => 8, 'output' => 9, 'total' => 17]);
    $second_child->save();

    $first_result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'sessionId' => $context['session']->id(),
      'draftVersion' => 1,
      'fields' => [
        'title' => [['value' => 'Version one']],
      ],
    ]);
    $this->assertEquals(200, $first_result['status'], 'Expected 200 response. Body: ' . json_encode($first_result['body']));
    $first_node = \Drupal::entityTypeManager()->getStorage('node')->load($first_result['body']['nodeId']);
    $first_provenance = \Drupal::entityTypeManager()->getStorage('ai_content_provenance')->loadByProperties([
      'entity_type' => 'node',
      'entity_id' => $first_result['body']['nodeId'],
      'revision_id' => $first_node->getRevisionId(),
    ]);
    $this->assertNotEmpty($first_provenance);
    $first_provenance = reset($first_provenance);
    $this->assertSame((int) $context['assistant']->id(), (int) $first_provenance->getMessage()?->id());

    $second_result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'sessionId' => $context['session']->id(),
      'draftVersion' => 2,
      'fields' => [
        'title' => [['value' => 'Version two']],
      ],
    ]);
    $this->assertEquals(200, $second_result['status'], 'Expected 200 response. Body: ' . json_encode($second_result['body']));
    $second_node = \Drupal::entityTypeManager()->getStorage('node')->load($second_result['body']['nodeId']);
    $second_provenance = \Drupal::entityTypeManager()->getStorage('ai_content_provenance')->loadByProperties([
      'entity_type' => 'node',
      'entity_id' => $second_result['body']['nodeId'],
      'revision_id' => $second_node->getRevisionId(),
    ]);
    $this->assertNotEmpty($second_provenance);
    $second_provenance = reset($second_provenance);
    $this->assertSame((int) $second_assistant->id(), (int) $second_provenance->getMessage()?->id());

    $latest_result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'sessionId' => $context['session']->id(),
      'fields' => [
        'title' => [['value' => 'Latest version']],
      ],
    ]);
    $this->assertEquals(200, $latest_result['status'], 'Expected 200 response. Body: ' . json_encode($latest_result['body']));
    $latest_node = \Drupal::entityTypeManager()->getStorage('node')->load($latest_result['body']['nodeId']);
    $latest_provenance = \Drupal::entityTypeManager()->getStorage('ai_content_provenance')->loadByProperties([
      'entity_type' => 'node',
      'entity_id' => $latest_result['body']['nodeId'],
      'revision_id' => $latest_node->getRevisionId(),
    ]);
    $this->assertNotEmpty($latest_provenance);
    $latest_provenance = reset($latest_provenance);
    $this->assertSame((int) $second_assistant->id(), (int) $latest_provenance->getMessage()?->id());
  }

  /**
   * Tests that nested child token usage is included in provenance totals.
   */
  public function testSaveAggregatesNestedTokenUsage(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->drupalLogin($user);

    $context = $this->prepareDraftContext($user);
    $message_storage = \Drupal::entityTypeManager()->getStorage('ai_conversation_message');
    $grandchild = $message_storage->create([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => (int) $context['session']->id(),
      'parent' => (int) $context['child']->id(),
      'role' => 'assistant',
      'agent_id' => 'grandchild-agent',
      'content' => 'Grandchild slice.',
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $grandchild->setTokenUsage(['input' => 4, 'output' => 6, 'total' => 10]);
    $grandchild->save();

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'sessionId' => $context['session']->id(),
      'fields' => [
        'title' => [['value' => 'Nested totals']],
      ],
    ]);

    $this->assertEquals(200, $result['status'], 'Expected 200 response. Body: ' . json_encode($result['body']));
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($result['body']['nodeId']);
    $provenance = \Drupal::entityTypeManager()->getStorage('ai_content_provenance')->loadByProperties([
      'entity_type' => 'node',
      'entity_id' => $result['body']['nodeId'],
      'revision_id' => $node->getRevisionId(),
    ]);
    $this->assertNotEmpty($provenance);
    $provenance = reset($provenance);
    $this->assertSame(['input' => 7, 'output' => 10, 'total' => 17], $provenance->getTokenUsage());
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
    $this->drupalLogin($user);

    $context = $this->prepareDraftContext($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'sessionId' => $context['session']->id(),
      'fields' => [
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
      ],
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . json_encode($result['body']));
    $nodeId = $result['body']['nodeId'];
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nodeId);
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
   * Tests that save with an invalid bundle returns 400.
   *
   * The user must have create permission for the bundle being tested, so we
   * use an admin user. Otherwise the permission check would reject the request
   * with 403 before the bundle validation is reached.
   */
  public function testSaveInvalidBundle(): void {
    $user = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($user);

    $context = $this->prepareDraftContext($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'nonexistent_bundle',
      'sessionId' => $context['session']->id(),
      'fields' => [
        'title' => 'x',
      ],
    ]);

    $this->assertEquals(400, $result['status']);
    $this->assertEquals('invalid_bundle', $result['body']['code']);
  }

  /**
   * Tests that save without create permission returns 403.
   */
  public function testSavePermissionDenied(): void {
    $user = $this->createUser([
      'use oe ai assistant',
    ]);
    $this->drupalLogin($user);

    $context = $this->prepareDraftContext($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'sessionId' => $context['session']->id(),
      'fields' => [
        'title' => 'Fail',
      ],
    ]);

    $this->assertEquals(403, $result['status']);
    $this->assertEquals('forbidden', $result['body']['code']);
  }

  /**
   * Sends a POST request with JSON body using the BrowserKit client.
   *
   * @param string $url
   *   The URL to post to.
   * @param array $body
   *   The request body to encode as JSON.
   *
   * @return array
   *   An array with 'status' and 'body' keys.
   */
  protected function httpPost(string $url, array $body): array {
    /** @var \Symfony\Component\BrowserKit\AbstractBrowser $client */
    $client = $this->getSession()->getDriver()->getClient();

    $fullUrl = $this->baseUrl . $url;

    $client->request(
      'POST',
      $fullUrl,
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($body),
    );

    $response = $client->getResponse();

    return [
      'status' => $response->getStatusCode(),
      'body' => json_decode($response->getContent(), TRUE),
    ];
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

  /**
   * Creates the shared session, template, and triggering assistant turn.
   *
   * @return array{session:\Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface, template:\Drupal\Core\Config\Entity\ConfigEntityInterface, assistant:\Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface, child:\Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface}
   *   The draft context used by the save endpoint.
   */
  protected function prepareDraftContext(UserInterface $user): array {
    $template = \Drupal::entityTypeManager()->getStorage('ai_drafting_template')->create([
      'id' => 'save_provenance_' . uniqid(),
      'label' => 'Save provenance',
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'x']],
    ]);
    $template->save();
    $this->markEntityForCleanup($template);

    $session = \Drupal::entityTypeManager()->getStorage('ai_editorial_session')->create([
      'type' => 'content_creation',
      'uid' => $user->id(),
      'content_type' => 'oe_news',
      'template' => $template->id(),
    ]);
    $session->save();

    $assistant = \Drupal::entityTypeManager()->getStorage('ai_conversation_message')->create([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => (int) $session->id(),
      'role' => 'assistant',
      'agent_id' => 'orchestrator',
      'content' => 'Draft ready.',
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $assistant->setToolCalls([
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content', 'arguments' => '{}'],
      ],
    ]);
    $assistant->setTokenUsage(['input' => 1, 'output' => 1, 'total' => 2]);
    $assistant->save();

    $child = \Drupal::entityTypeManager()->getStorage('ai_conversation_message')->create([
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => (int) $session->id(),
      'parent' => (int) $assistant->id(),
      'role' => 'assistant',
      'agent_id' => 'title-agent',
      'content' => 'Title slice.',
      'provider' => 'mock',
      'model' => 'mock-model',
    ]);
    $child->setTokenUsage(['input' => 2, 'output' => 3, 'total' => 5]);
    $child->save();

    return [
      'session' => $session,
      'template' => $template,
      'assistant' => $assistant,
      'child' => $child,
    ];
  }

}
