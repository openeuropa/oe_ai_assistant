<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

/**
 * Kernel tests for AI editorial session access control.
 */
class AiEditorialSessionAccessTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests create access follows target content type permissions.
   */
  public function testCreateAccess(): void {
    $access_handler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler('ai_editorial_session');

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
   * Tests owners, collaborators, and admins get expected entity access.
   */
  public function testEntityAccess(): void {
    $owner = $this->createUser();
    $collaborator = $this->createUser(['access content', 'bypass node access']);
    $viewer = $this->createUser(['access content']);
    $admin = $this->createUser(['administer ai editorial sessions']);

    $node = $this->createPublishedNode('oe_news', 'Shared node');

    $private_session = $this->createSession($owner);
    $shared_session = $this->createSession($owner, $node);

    $this->assertTrue($private_session->access('view', $owner));
    $this->assertTrue($private_session->access('update', $owner));
    $this->assertFalse($private_session->access('view', $viewer));
    $this->assertFalse($private_session->access('update', $viewer));

    $this->assertTrue($shared_session->access('view', $admin));
    $this->assertTrue($shared_session->access('update', $admin));
    $this->assertTrue($shared_session->access('delete', $admin));
    $this->assertFalse($shared_session->access('delete', $owner));
  }

  /**
   * Tests access-checked entity queries only return visible sessions.
   */
  public function testEntityQueryFiltering(): void {
    $owner = $this->createUser();
    $viewer = $this->createUser(['access content']);

    $visible_node = $this->createPublishedNode('oe_news', 'Visible node');

    $own_session = $this->createSession($viewer);
    $shared_visible_session = $this->createSession($owner, $visible_node);
    $private_session = $this->createSession($owner);

    $this->container->get('current_user')->setAccount($viewer);

    $ids = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(TRUE)
      ->sort('id')
      ->execute();

    $this->assertArrayHasKey($own_session->id(), $ids);
    $this->assertArrayNotHasKey($private_session->id(), $ids);
  }

}
