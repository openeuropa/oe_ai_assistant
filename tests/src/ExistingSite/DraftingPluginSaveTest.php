<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
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
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown(): void {
    $this->deleteTestEntities();
    parent::tearDown();
  }

  /**
   * Tests that save creates a node with simple fields in the new payload shape.
   */
  public function testSaveCreatesNodeWithSimpleFields(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
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

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
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
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'nonexistent_bundle',
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
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
      'fields' => [
        'title' => 'Fail',
      ],
    ]);

    $this->assertEquals(403, $result['status']);
    $this->assertEquals('forbidden', $result['body']['code']);
  }

  /**
   * Logs in a user via the login form.
   *
   * Similar to drupalLogin() but skips the drupalUserIsLoggedIn() assertion
   * which fails due to session name mismatch between the test runner and the
   * browser hostname.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account to log in.
   */
  protected function loginUser(UserInterface $account): void {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    $this->drupalGet(Url::fromRoute('user.login'));
    $this->submitForm([
      'name' => $account->getAccountName(),
      'pass' => $account->passRaw,
    ], 'Log in');

    $this->loggedInUser = $account;
    $this->container->get('current_user')->setAccount($account);
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

}
