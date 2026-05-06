<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\Core\Url;

/**
 * Functional tests for the AI editorial session admin UI.
 */
class AiEditorialSessionDashboardTest extends AiEditorialSessionBrowserTestBase {

  /**
   * Tests the dashboard renders the expected listing and operations.
   */
  public function testDashboard(): void {
    $user = $this->drupalCreateUser([
      'access administration pages',
      'access content overview',
      'administer ai editorial sessions',
    ]);
    $this->drupalLogin($user);

    $node = $this->createPublishedNode('oe_news', 'Linked node');
    $session = $this->createSession($user, $node);

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.collection'));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI editorial sessions');
    $this->assertSession()->pageTextContains('Add new session');
    $this->assertSession()->pageTextContains('Label');
    $this->assertSession()->pageTextContains('Bundle');
    $this->assertSession()->pageTextContains('Content type');
    $this->assertSession()->pageTextContains('Template');
    $this->assertSession()->pageTextContains('Node');
    $this->assertSession()->pageTextContains('Owner');
    $this->assertSession()->pageTextContains('Status');
    $this->assertSession()->pageTextContains('Created');
    $this->assertSession()->pageTextContains('Changed');
    $this->assertSession()->pageTextContains($session->label());
    $this->assertSession()->pageTextContains('oe_news');
    $this->assertSession()->pageTextContains('landing_page');
    $this->assertSession()->pageTextContains('Linked node');
    $this->assertSession()->pageTextContains('active');
    $this->assertSession()->linkExists('Continue');
    $this->assertSession()->linkExists('Delete');

    $this->clickLink('Continue');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Session placeholder page.');
    $this->assertSession()->pageTextContains('Content type: oe_news');
    $this->assertSession()->pageTextContains('Status: active');
  }

  /**
   * Tests the admin parent routes for content and configuration.
   */
  public function testAdminStructure(): void {
    $user = $this->drupalCreateUser([
      'access administration pages',
      'access content overview',
      'administer ai editorial sessions',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet(Url::fromRoute('system.admin_content'));
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.collection'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI editorial sessions');

    $this->drupalGet(Url::fromRoute('oe_ai_assistant.admin_config'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI Editorial Assistant');
  }

  /**
   * Tests the collection route can be accessed with the overview permission.
   */
  public function testDashboardAccessWithOverviewPermission(): void {
    $user = $this->drupalCreateUser([
      'access ai editorial sessions overview',
      'access content',
    ]);
    $owner = $this->drupalCreateUser();

    $visible_node = $this->createPublishedNode('oe_news', 'Visible node');
    $hidden_node = $this->drupalCreateNode([
      'type' => 'oe_news',
      'title' => 'Hidden node',
      'status' => 0,
    ]);

    $owned_session = $this->createSession($user, $hidden_node);
    $owned_session->set('label', 'Owned session');
    $owned_session->save();

    $shared_visible_session = $this->createSession($owner, $visible_node);
    $shared_visible_session->set('label', 'Shared visible session');
    $shared_visible_session->save();

    $private_session = $this->createSession($owner);
    $private_session->set('label', 'Private session');
    $private_session->save();

    $hidden_session = $this->createSession($owner, $hidden_node);
    $hidden_session->set('label', 'Hidden session');
    $hidden_session->save();

    $this->assertTrue($owned_session->access('view', $user));
    $this->assertTrue($shared_visible_session->access('view', $user));
    $this->assertFalse($private_session->access('view', $user));
    $this->assertFalse($hidden_session->access('view', $user));

    $this->drupalLogin($user);

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.collection'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI editorial sessions');
    $this->assertSession()->pageTextContains('Owned session');
    $this->assertSession()->pageTextContains('Shared visible session');
    $this->assertSession()->pageTextNotContains('Private session');
    $this->assertSession()->pageTextNotContains('Hidden session');

    $this->drupalLogout();

    $user = $this->drupalCreateUser();
    $this->drupalLogin($user);

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.collection'));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests creating a drafting session from the add flow.
   */
  public function testAddSessionFlow(): void {
    $user = $this->drupalCreateUser([
      'create oe_news content',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.add_page'));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('content_type');
    $this->assertSession()->fieldExists('template_id');

    $this->submitForm([
      'content_type' => 'oe_news',
      'template_id' => 'landing_page',
    ], 'Save');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Session placeholder page.');
    $this->assertSession()->pageTextContains('Content type: oe_news');
    $this->assertSession()->pageTextContains('Status: active');

    $sessions = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->loadByProperties([
        'uid' => $user->id(),
        'content_type' => 'oe_news',
        'template_id' => 'landing_page',
      ]);

    $session = reset($sessions);
    $this->assertNotFalse($session);
    $this->assertSame('drafting', $session->bundle());
  }

}
