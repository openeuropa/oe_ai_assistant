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
   * Tests that save creates a node with simple fields.
   */
  public function testSaveCreatesNodeWithSimpleFields(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'bundle' => 'oe_news',
      'fields' => [
        'title' => 'Test Save',
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
