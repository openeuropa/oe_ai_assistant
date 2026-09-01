<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\media\Entity\Media;

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
    $this->assertSession()->pageTextContains('AI Editorial Sessions');
    $this->assertSession()->pageTextContains('Add new session');
    $this->assertSession()->pageTextContains('Session');
    $this->assertSession()->pageTextContains('Type');
    $this->assertSession()->pageTextContains('Target');
    $this->assertSession()->pageTextContains('Initiated by');
    $this->assertSession()->pageTextContains('Status');
    $this->assertSession()->pageTextContains('Created');
    $this->assertSession()->pageTextContains('Changed');
    $this->assertSession()->pageTextContains($session->label());
    // Human readable labels, never machine names.
    $this->assertSession()->pageTextContains('News');
    $this->assertSession()->pageTextContains('Content creation');
    $this->assertSession()->pageTextContains('Active');
    $this->assertSession()->pageTextNotContains('content_creation');
    $this->assertSession()->linkExists('Continue');
    $this->assertSession()->linkByHrefExists($session->toUrl('canonical')->toString());
    $this->assertSession()->linkExists('Delete');
    // The region-less session template only applies on the session route.
    $this->assertSession()->responseNotContains('oe-ai-session-page');

    $this->clickLink('Continue');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame($session->toUrl('canonical', ['absolute' => TRUE])->toString(), $this->getUrl());
    $this->assertSessionAppPage('oe_news', (string) $session->id());
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
    $this->assertSession()->pageTextContains('AI Editorial Sessions');

    $admin_settings_url = Url::fromRoute('oe_ai_assistant.admin_settings');
    $dashboard_url = Url::fromRoute('entity.ai_editorial_session.collection');

    $this->drupalGet(Url::fromRoute('system.admin_config'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI Editorial tools');
    $this->assertSession()->pageTextContains('AI Editorial Sessions');
    $this->assertSession()->linkExists('AI Editorial Sessions');
    $this->assertSession()->linkByHrefExists($dashboard_url->toString());

    $this->drupalGet($admin_settings_url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI Editorial Tools');
    $this->assertSession()->pageTextContains('AI Editorial Sessions');
    $this->assertSession()->pageTextContains('List and manage AI editorial sessions.');
    $this->assertSession()->linkExists('AI Editorial Sessions');
    $this->assertSession()->linkByHrefExists($dashboard_url->toString());
  }

  /**
   * Tests the collection route can be accessed with the overview permission.
   */
  public function testDashboardAccessWithOverviewPermission(): void {
    $user = $this->drupalCreateUser([
      'view_update own sessions',
      'access content',
    ]);
    $owner = $this->drupalCreateUser();

    $this->createPublishedNode('oe_news', 'Visible node');
    $hidden_node = $this->drupalCreateNode([
      'type' => 'oe_news',
      'title' => 'Hidden node',
      'status' => 0,
    ]);

    $owned_session = $this->createSession($user, $hidden_node);
    $owned_session->set('label', 'Owned session');
    $owned_session->save();
    $private_session = $this->createSession($owner);
    $private_session->set('label', 'Private session');
    $private_session->save();

    $hidden_session = $this->createSession($owner, $hidden_node);
    $hidden_session->set('label', 'Hidden session');
    $hidden_session->save();

    $this->assertTrue($owned_session->access('view', $user));
    $this->assertFalse($private_session->access('view', $user));
    $this->assertFalse($hidden_session->access('view', $user));

    $this->drupalLogin($user);

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.collection'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('AI Editorial Sessions');
    $this->assertSession()->pageTextContains('Owned session');
    $this->assertSession()->pageTextNotContains('Private session');
    $this->assertSession()->pageTextNotContains('Hidden session');

    $this->drupalLogout();

    $user = $this->drupalCreateUser();
    $this->drupalLogin($user);

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.collection'));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests the session route renders the AI Assistant app and checks access.
   */
  public function testSessionPageRendersAppAndChecksAccess(): void {
    $owner = $this->drupalCreateUser([
      'view_update own sessions',
      'access content',
    ]);
    $other_user = $this->drupalCreateUser([
      'view_update own sessions',
      'access content',
    ]);

    $session = $this->createSession($owner);

    $this->drupalLogin($owner);
    $this->drupalGet($session->toUrl('canonical'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSessionAppPage('oe_news', (string) $session->id());

    $this->drupalLogin($other_user);
    $this->drupalGet($session->toUrl('canonical'));
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->elementNotExists('css', '#oe-ai-assistant[data-ai-app]');
  }

  /**
   * Tests that empty editorial context vocabularies bootstrap as empty lists.
   */
  public function testSessionPageRendersEmptyEditorialContextOptions(): void {
    $this->deleteEditorialContextTerms();
    $owner = $this->drupalCreateUser([
      'view_update own sessions',
      'access content',
    ]);
    $session = $this->createSession($owner);

    $this->drupalLogin($owner);
    $this->drupalGet($session->toUrl('canonical'));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"tone":{"enabled":true,"options":[]');
    $this->assertSession()->responseContains('"documents":{"enabled":true,"options":[]}');
    $this->assertSession()->responseNotContains('oe_ai_prompt');
  }

  /**
   * Tests the session page bootstraps referenced context documents.
   */
  public function testSessionPageRehydratesContextDocuments(): void {
    $owner = $this->drupalCreateUser([
      'view_update own sessions',
      'access content',
    ]);
    $session = $this->createSession($owner);
    $directory = 'private://ai-context-documents';
    $this->container->get('file_system')->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
    $file = $this->container->get('file.repository')->writeData(
      'Existing context document',
      $directory . '/context_text.md',
      FileExists::Rename,
    );
    $file->setPermanent();
    $file->save();
    $media = Media::create([
      'bundle' => 'ai_context_document',
      'name' => 'Policy brief',
      'status' => 0,
      'oe_ai_context_document' => [
        'target_id' => $file->id(),
      ],
    ]);
    $media->save();
    $session->get('context_documents')->appendItem([
      'target_id' => $media->id(),
    ]);
    $session->save();

    $this->drupalLogin($owner);
    $this->drupalGet($session->toUrl('canonical'));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"documents":{"enabled":true,"options":[{');
    $this->assertSession()->responseContains('"id":"' . $media->id() . '"');
    $this->assertSession()->responseContains('"title":"Policy brief"');
    $this->assertSession()->responseContains('"meta":{"type":"md","size":');
  }

  /**
   * Tests the session route delegates access to the session entity handler.
   */
  public function testSessionRouteUsesEntityAccessRequirement(): void {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('entity.ai_editorial_session.canonical');

    $this->assertSame('/admin/content/ai/{ai_editorial_session}', $route->getPath());
    $this->assertSame(
      '\Drupal\oe_ai_assistant\Controller\AiEditorialSessionController::view',
      $route->getDefault('_controller')
    );
    $this->assertSame('ai_editorial_session.view', $route->getRequirement('_entity_access'));
    $parameters = $route->getOption('parameters');
    $this->assertSame('entity:ai_editorial_session', $parameters['ai_editorial_session']['type']);
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
    $this->assertSession()->fieldExists('label');

    // Choosing the content type scopes the mandatory template select. The first
    // submit leaves it empty, so the form redisplays with the scoped options.
    $this->submitForm([
      'content_type' => 'oe_news',
      'label' => 'my session',
      'tone' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
    ], 'Save');

    // The template options now include news_default; select it and save.
    $this->submitForm([
      'template' => 'news_default',
    ], 'Save');

    $this->assertSession()->statusCodeEquals(200);
    $session = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->loadByProperties(['label' => 'my session']);
    $session = reset($session);
    $this->assertNotFalse($session);
    $this->assertSame('news_default', $session->get('template')->target_id);
    $this->assertSame($session->toUrl('canonical', ['absolute' => TRUE])->toString(), $this->getUrl());
    $this->assertSessionAppPage('oe_news', (string) $session->id());
  }

  /**
   * Tests the add form only lists content types the user can create.
   */
  public function testAddSessionContentTypeOptionsRespectCreateAccess(): void {
    $user = $this->drupalCreateUser([
      'create oe_news content',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.add_form', [
      'ai_editorial_session_type' => 'content_creation',
    ]));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'select[name="content_type"] option[value="oe_news"]');
    $this->assertSession()->elementNotExists('css', 'select[name="content_type"] option[value="oe_contact"]');
  }

  /**
   * Tests the node tab no longer exposes or launches the app.
   */
  public function testNodeTabDoesNotExposeApp(): void {
    $user = $this->drupalCreateUser([
      'access content',
      'use oe ai assistant',
    ]);
    $this->drupalLogin($user);
    $node = $this->createPublishedNode('oe_news', 'Node without assistant tab');

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkNotExists('AI Assistant');

    $this->drupalGet('/node/' . $node->id() . '/ai-assistant');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->elementNotExists('css', '#oe-ai-assistant[data-ai-app]');
  }

  /**
   * Asserts the current page contains the React app bootstrap.
   *
   * @param string $bundle
   *   The expected drafting bundle configured for the session.
   * @param string $sessionId
   *   The expected AI editorial session entity ID.
   */
  protected function assertSessionAppPage(string $bundle, string $sessionId): void {
    $session = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->load($sessionId);
    // The module-provided region-less page template wraps the mount.
    $this->assertSession()->elementExists('css', '.oe-ai-session-page #oe-ai-assistant[data-ai-app]');
    $this->assertSession()->responseContains('oe_ai_assistant/js/init.js');
    $this->assertSession()->responseContains('dist/ai-editorial-assistant.iife.js');
    $this->assertSession()->responseContains('oeAiAssistant');
    $this->assertSession()->responseContains('"apiBaseUrl":"\/api\/ai"');
    $this->assertSession()->responseContains('"userId":"' . $this->loggedInUser->id() . '"');
    $this->assertSession()->responseContains('"userName":' . json_encode($this->loggedInUser->getDisplayName()));
    $this->assertSession()->responseContains('"sessionId":"' . $sessionId . '"');
    $this->assertSession()->responseContains('"sessionTitle":' . json_encode('Content creation: ' . $session->label()));
    $this->assertSession()->responseContains('"exitUrl":"\/admin\/content\/ai"');
    $this->assertSession()->responseContains('"disclaimer":"AI assistant can make mistakes. Please double-check responses."');
    $this->assertSession()->responseContains('"enabledPlugins":["drafting","echo","notes"]');
    $this->assertSession()->responseContains('"entityTypeId":"node"');
    $this->assertSession()->responseContains('"bundle":"' . $bundle . '"');
    $this->assertSession()->responseContains('"tone":{"enabled":true');
    $this->assertSession()->responseContains(json_encode([
      'id' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
      'label' => 'Formal',
      'description' => 'A professional and neutral tone suitable for official or institutional communication.',
    ]));
    $this->assertSession()->responseContains('"label":"Formal"');
    $this->assertSession()->responseContains('"description":"A professional and neutral tone suitable for official or institutional communication."');
    $this->assertSession()->responseContains('"documents":{"enabled":true,"options":[]}');
    $this->assertSession()->responseNotContains('"name":"Formal"');
    $this->assertSession()->responseNotContains('oe_ai_prompt');
  }

  /**
   * Returns the taxonomy term ID for a fixture term.
   */
  protected function getTermIdByName(string $vid, string $name): string {
    $terms = $this->container
      ->get('entity_type.manager')
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vid,
        'name' => $name,
      ]);

    $term = reset($terms);
    if (!$term) {
      $this->fail(sprintf('Term "%s" was not found in "%s".', $name, $vid));
    }

    return (string) $term->id();
  }

  /**
   * Deletes all editorial context terms from the test site.
   */
  protected function deleteEditorialContextTerms(): void {
    $storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'oe_ai_tone')
      ->execute();

    if ($ids) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

}
