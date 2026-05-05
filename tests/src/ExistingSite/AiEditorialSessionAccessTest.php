<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for AI editorial session access control.
 */
class AiEditorialSessionAccessTest extends ExistingSiteBase {

  /**
   * The IDs of existing entities before the test, keyed by entity type.
   *
   * @var array<string, array<int|string, int|string>>
   */
  protected array $existingEntityIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->trackEntityType('ai_editorial_session');
    $this->trackEntityType('node');
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown(): void {
    $this->deleteTestEntities();
    parent::tearDown();
  }

  /**
   * Tests create access follows the target content type permissions.
   */
  public function testCreateAccess(): void {
    $access_handler = \Drupal::entityTypeManager()->getAccessControlHandler('ai_editorial_session');

    $author = $this->createUser(['create oe_news content']);
    $viewer = $this->createUser();
    $admin = $this->createUser(['administer ai editorial sessions']);

    $this->assertTrue($access_handler->createAccess('drafting', $author, ['content_type' => 'oe_news']));
    $this->assertFalse($access_handler->createAccess('drafting', $author, ['content_type' => 'oe_contact']));
    $this->assertTrue($access_handler->createAccess('drafting', $author));
    $this->assertFalse($access_handler->createAccess('drafting', $viewer, ['content_type' => 'oe_news']));
    $this->assertTrue($access_handler->createAccess('drafting', $admin, ['content_type' => 'oe_news']));
  }

  /**
   * Tests owners, collaborators, and admins get the expected access.
   */
  public function testEntityAccess(): void {
    $owner = $this->createUser();
    $collaborator = $this->createUser(['access content', 'bypass node access']);
    $viewer = $this->createUser(['access content']);
    $admin = $this->createUser(['administer ai editorial sessions']);

    $node = Node::create([
      'type' => 'oe_news',
      'title' => 'Shared node',
      'status' => 1,
      'moderation_state' => 'published',
    ]);
    $node->save();

    $private_session = $this->createSession($owner, NULL);
    $shared_session = $this->createSession($owner, $node);

    $this->assertTrue($private_session->access('view', $owner));
    $this->assertTrue($private_session->access('update', $owner));
    $this->assertFalse($private_session->access('view', $viewer));
    $this->assertFalse($private_session->access('update', $viewer));

    $this->assertTrue($shared_session->access('view', $viewer));
    $this->assertFalse($shared_session->access('update', $viewer));
    $this->assertTrue($shared_session->access('view', $collaborator));
    $this->assertTrue($shared_session->access('update', $collaborator));

    $this->assertTrue($shared_session->access('view', $admin));
    $this->assertTrue($shared_session->access('update', $admin));
    $this->assertTrue($shared_session->access('delete', $admin));
    $this->assertFalse($shared_session->access('delete', $owner));
  }

  /**
   * Tests entity queries with access checks only return visible sessions.
   */
  public function testEntityQueryFiltering(): void {
    $owner = $this->createUser();
    $viewer = $this->createUser(['access content']);

    $visible_node = Node::create([
      'type' => 'oe_news',
      'title' => 'Visible node',
      'status' => 1,
      'moderation_state' => 'published',
    ]);
    $visible_node->save();

    $own_session = $this->createSession($viewer, NULL);
    $shared_visible_session = $this->createSession($owner, $visible_node);
    $private_session = $this->createSession($owner, NULL);

    $this->loginUser($viewer);

    $ids = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(TRUE)
      ->sort('id')
      ->execute();

    $this->assertArrayHasKey($own_session->id(), $ids);
    $this->assertArrayHasKey($shared_visible_session->id(), $ids);
    $this->assertArrayNotHasKey($private_session->id(), $ids);
  }

  /**
   * Creates a drafting session for a given owner.
   */
  protected function createSession(UserInterface $owner, ?Node $node): AiEditorialSessionInterface {
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session')
      ->create([
        'bundle' => 'drafting',
        'uid' => $owner->id(),
        'content_type' => 'oe_news',
        'template_id' => 'landing_page',
        'node_id' => $node?->id(),
      ]);
    $session->save();

    return $session;
  }

  /**
   * Logs in a user via the login form.
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
