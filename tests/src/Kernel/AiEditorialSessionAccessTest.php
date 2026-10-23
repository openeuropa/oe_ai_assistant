<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for AI editorial session access control.
 */
#[Group('oe_ai_assistant')]
class AiEditorialSessionAccessTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * Tests create access follows target content type permissions.
   */
  public function testCreateAccess(): void {
    $access_handler = $this->container->get('entity_type.manager')
      ->getAccessControlHandler('ai_editorial_session');

    $author = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $gateless_author = $this->createUser(['create oe_news content']);
    $viewer = $this->createUser();
    $admin = $this->createUser(['administer ai editorial sessions']);

    $this->assertTrue($access_handler->createAccess('drafting', $author, ['content_type' => 'oe_news']));
    $this->assertFalse($access_handler->createAccess('drafting', $author, ['content_type' => 'oe_contact']));
    $this->assertTrue($access_handler->createAccess('drafting', $author));
    $this->assertFalse($access_handler->createAccess('drafting', $viewer, ['content_type' => 'oe_news']));
    $this->assertTrue($access_handler->createAccess('drafting', $admin, ['content_type' => 'oe_news']));

    // Without 'use oe ai assistant', content-type create permission alone no
    // longer grants session create access, closing the orphaned-session gap.
    $this->assertFalse($access_handler->createAccess('drafting', $gateless_author, ['content_type' => 'oe_news']));
    $this->assertFalse($access_handler->createAccess('drafting', $gateless_author));
  }

  /**
   * Tests owners, collaborators, and admins get expected entity access.
   */
  public function testEntityAccess(): void {
    $owner = $this->createUser([
      'use oe ai assistant',
      'access content',
      'create oe_news content',
      'edit own oe_news content',
    ]);
    $collaborator = $this->createUser(['use oe ai assistant', 'access content', 'edit any oe_news content']);
    $viewer = $this->createUser(['access content']);
    $admin = $this->createUser(['administer ai editorial sessions']);

    $node = $this->createPublishedNode('oe_news', 'Shared node');
    $node->setOwnerId((int) $owner->id())->save();

    $private_session = $this->createSession($owner);
    $shared_session = $this->createSession($owner, $node);

    // No node yet: pre-save fallback is the content_type create permission,
    // not ownership (see testPreSaveFallbackUsesContentTypePermission for
    // the full matrix) — the owner still passes because they hold it.
    $this->assertTrue($private_session->access('view', $owner));
    $this->assertTrue($private_session->access('update', $owner));
    $this->assertFalse($private_session->access('view', $viewer));
    $this->assertFalse($private_session->access('update', $viewer));

    // Node-based collaboration: an account with node access can see the
    // session once it has a node, whether or not they own it — but only if
    // they also hold 'use oe ai assistant'. $viewer has node access via
    // 'access content' but lacks that permission, so it stays forbidden.
    $this->assertFalse($shared_session->access('view', $viewer));
    $this->assertFalse($shared_session->access('update', $viewer));
    $this->assertTrue($shared_session->access('view', $collaborator));
    $this->assertTrue($shared_session->access('update', $collaborator));
    $this->assertTrue($shared_session->access('view', $owner));
    $this->assertTrue($shared_session->access('update', $owner));

    // The create-permission tier only falls back while the node is
    // unpublished (see testContentTypeFallbackOnlyAppliesBeforePublication);
    // $node here is published, so an account with no real node access is
    // denied even though it holds 'create oe_news content'.
    $fallbackCollaborator = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->assertFalse($shared_session->access('view', $fallbackCollaborator));
    $this->assertFalse($shared_session->access('update', $fallbackCollaborator));

    $this->assertTrue($shared_session->access('view', $admin));
    $this->assertTrue($shared_session->access('update', $admin));
    $this->assertTrue($shared_session->access('delete', $admin));
    $this->assertFalse($shared_session->access('delete', $owner));
  }

  /**
   * Tests node/content-type/ownership access alone is not enough.
   *
   * 'use oe ai assistant' gates the whole feature per its own permission
   * description ("Access the AI Assistant tab on nodes and use its API
   * endpoints"); none of the other collaboration tiers may bypass it.
   */
  public function testUseAiAssistantPermissionIsRequired(): void {
    $node_collaborator = $this->createUser(['access content', 'edit any oe_news content']);
    $content_type_collaborator = $this->createUser(['create oe_news content']);
    $owner_without_permission = $this->createUser(['create oe_news content']);
    $admin = $this->createUser(['administer ai editorial sessions']);

    $node_owner = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $node = $this->createPublishedNode('oe_news', 'Gated node');
    $shared_session = $this->createSession($node_owner, $node);
    $owned_session = $this->createSession($owner_without_permission);

    // Node access and content-type create access both fail to grant session
    // access without 'use oe ai assistant', even though each alone would
    // pass in testEntityAccess() when paired with it.
    $this->assertFalse($shared_session->access('view', $node_collaborator));
    $this->assertFalse($shared_session->access('update', $node_collaborator));
    $this->assertFalse($shared_session->access('view', $content_type_collaborator));
    $this->assertFalse($shared_session->access('update', $content_type_collaborator));

    // Ownership is not a bypass either.
    $this->assertFalse($owned_session->access('view', $owner_without_permission));
    $this->assertFalse($owned_session->access('update', $owner_without_permission));

    // The site-wide admin permission still bypasses the gate, unaffected.
    $this->assertTrue($shared_session->access('view', $admin));
    $this->assertTrue($shared_session->access('update', $admin));
  }

  /**
   * Tests the content_type fallback only applies before publication.
   */
  public function testContentTypeFallbackOnlyAppliesBeforePublication(): void {
    $creator = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $fallbackCollaborator = $this->createUser(['use oe ai assistant', 'create oe_news content']);

    $unpublished_node = Node::create(['type' => 'oe_news', 'title' => 'Draft node', 'status' => 0]);
    $unpublished_node->save();
    $unpublished_session = $this->createSession($creator, $unpublished_node);

    $published_node = $this->createPublishedNode('oe_news', 'Published node');
    $published_session = $this->createSession($creator, $published_node);

    // No node access (lacks 'access content'), but the content_type
    // permission still collaborates while the node is unpublished.
    $this->assertTrue($unpublished_session->access('view', $fallbackCollaborator));
    $this->assertTrue($unpublished_session->access('update', $fallbackCollaborator));

    // Once the node is published, the content_type permission alone no
    // longer suffices; real node access (or ownership) is required.
    $this->assertFalse($published_session->access('view', $fallbackCollaborator));
    $this->assertFalse($published_session->access('update', $fallbackCollaborator));

    // Entity queries mirror the same restriction.
    $this->container->get('current_user')->setAccount($fallbackCollaborator);
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(TRUE)
      ->execute();
    $this->assertArrayHasKey($unpublished_session->id(), $ids);
    $this->assertArrayNotHasKey($published_session->id(), $ids);
  }

  /**
   * Tests the pre-save fallback delegates to the content_type permission.
   */
  public function testPreSaveFallbackUsesContentTypePermission(): void {
    $creator = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $colleague = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $outsider = $this->createUser(['access content']);

    $session = $this->createSession($creator);

    $this->assertNull($session->getNode());
    $this->assertTrue($session->access('view', $creator));
    $this->assertTrue($session->access('update', $creator));
    // Any account holding the target bundle's create permission
    // collaborates pre-save too, not just the creator.
    $this->assertTrue($session->access('view', $colleague));
    $this->assertTrue($session->access('update', $colleague));
    $this->assertFalse($session->access('view', $outsider));
    $this->assertFalse($session->access('update', $outsider));
  }

  /**
   * Tests the last-resort owner fallback when content_type is also unset.
   */
  public function testPreSaveFallbackWithoutContentTypeFallsBackToOwner(): void {
    $owner = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $other = $this->createUser(['use oe ai assistant', 'create oe_news content']);

    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->create([
        'type' => 'content_creation',
        'uid' => $owner->id(),
      ]);
    $session->save();

    $this->assertSame('', $session->getContentType());
    $this->assertTrue($session->access('view', $owner));
    $this->assertTrue($session->access('update', $owner));
    $this->assertFalse($session->access('view', $other));
    $this->assertFalse($session->access('update', $other));
  }

  /**
   * Tests access-checked entity queries only return visible sessions.
   */
  public function testEntityQueryFiltering(): void {
    $owner = $this->createUser();
    $editor = $this->createUser(['use oe ai assistant', 'access content', 'edit any oe_news content']);
    $viewOnly = $this->createUser(['use oe ai assistant', 'access content']);

    $visible_node = $this->createPublishedNode('oe_news', 'Visible node');

    $own_session = $this->createSession($editor);
    $shared_session = $this->createSession($owner, $visible_node);
    $private_session = $this->createSession($owner);

    $this->container->get('current_user')->setAccount($editor);

    $ids = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(TRUE)
      ->sort('id')
      ->execute();

    $this->assertArrayHasKey($own_session->id(), $ids);
    // Collaboration via node access: $editor does not own this session, but
    // can edit the node attached to it.
    $this->assertArrayHasKey($shared_session->id(), $ids);
    $this->assertArrayNotHasKey($private_session->id(), $ids);

    // Node view access alone does not collaborate; this mirrors the
    // entity-level check, which requires update.
    $this->container->get('current_user')->setAccount($viewOnly);
    $view_only_ids = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(TRUE)
      ->execute();
    $this->assertArrayNotHasKey($shared_session->id(), $view_only_ids);
  }

  /**
   * Tests query filtering against real node access grants.
   */
  public function testEntityQueryFilteringRespectsNodeAccessGrants(): void {
    $this->enableModules(['node_access_test']);

    $creator = $this->createUser(['use oe ai assistant']);
    // node_access_test grants the node owner update automatically, with no
    // 'edit own/any' permission at all.
    $nodeOwner = $this->createUser(['use oe ai assistant', 'access content']);
    // node_access_test grants 'node test view' holders view only.
    $viewGrantee = $this->createUser(['use oe ai assistant', 'access content', 'node test view']);

    $node = $this->createPublishedNode('oe_news', 'Grant-governed node');
    $node->setOwnerId((int) $nodeOwner->id())->save();

    $session = $this->createSession($creator, $node);

    $this->container->get('current_user')->setAccount($nodeOwner);
    $owner_ids = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(TRUE)
      ->execute();
    $this->assertArrayHasKey($session->id(), $owner_ids);

    $this->container->get('current_user')->setAccount($viewGrantee);
    $view_grantee_ids = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(TRUE)
      ->execute();
    $this->assertArrayNotHasKey($session->id(), $view_grantee_ids);
  }

  /**
   * Tests a node with translations does not inflate the session count.
   */
  public function testEntityQueryFilteringDoesNotDuplicateTranslatedNodes(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    \Drupal::service('content_translation.manager')->setEnabled('node', 'oe_news', TRUE);

    $editor = $this->createUser(['use oe ai assistant', 'access content', 'edit any oe_news content']);
    $node = $this->createPublishedNode('oe_news', 'Translatable node');
    $node->addTranslation('fr', ['title' => 'Nœud traduit'])->save();

    $this->createSession($editor, $node);

    $this->container->get('current_user')->setAccount($editor);
    $count = (int) $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(TRUE)
      ->count()
      ->execute();

    $this->assertSame(1, $count);
  }

}
