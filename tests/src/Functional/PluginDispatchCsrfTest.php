<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * Tests CSRF protection of the cookie-authenticated plugin dispatch route.
 *
 * The plugin actions are called by the React app with the browser session
 * cookie. Without a CSRF token requirement, any third-party page can push
 * files into a victim's editorial session with a text/plain fetch (a CORS
 * "simple" request that needs no preflight) or call JSON actions with a
 * text/plain form whose body happens to parse as JSON.
 */
class PluginDispatchCsrfTest extends AiEditorialSessionBrowserTestBase {

  /**
   * Tests a raw-body add-document request without a token is refused.
   */
  public function testAddDocumentRequiresCsrfToken(): void {
    $owner = $this->createOwner();
    $session = $this->createSession($owner);
    $this->drupalLogin($owner);

    $response = $this->getHttpClient()->request('POST', $this->uploadUrl($session->id()), [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
      'headers' => ['Content-Type' => 'application/octet-stream'],
      'body' => 'Context document contents.',
    ]);

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSessionHasNoDocuments((string) $session->id());
  }

  /**
   * Tests a JSON action posted as text/plain without a token is refused.
   *
   * The controller never checks the Content-Type, so a cross-site HTML
   * form with enctype="text/plain" can deliver a JSON body.
   */
  public function testJsonActionRequiresCsrfToken(): void {
    $owner = $this->createOwner();
    $session = $this->createSession($owner);
    $this->drupalLogin($owner);

    $response = $this->getHttpClient()->request('POST', $this->actionUrl('list-documents'), [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
      'headers' => ['Content-Type' => 'text/plain'],
      'body' => json_encode([
        'sessionId' => (string) $session->id(),
        'category' => 'context',
      ], JSON_THROW_ON_ERROR),
    ]);

    $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
  }

  /**
   * Tests a request carrying the session token is still accepted.
   *
   * Companion to the two tests above: it passes today and must keep
   * passing once the route requires the token.
   */
  public function testAddDocumentAcceptsCsrfToken(): void {
    $owner = $this->createOwner();
    $session = $this->createSession($owner);
    $this->drupalLogin($owner);

    $this->drupalGet(Url::fromRoute('system.csrftoken'));
    $token = $this->getSession()->getPage()->getContent();

    $response = $this->getHttpClient()->request('POST', $this->uploadUrl($session->id()), [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
      'headers' => [
        'X-CSRF-Token' => $token,
        'Content-Type' => 'application/octet-stream',
      ],
      'body' => 'Context document contents.',
    ]);

    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('brief.txt', $payload['document']['title']);
  }

  /**
   * Creates a user allowed to use the assistant on their own sessions.
   */
  private function createOwner(): UserInterface {
    return $this->drupalCreateUser([
      'use oe ai assistant',
      'view_update own sessions',
      'access content',
    ]);
  }

  /**
   * Builds the absolute URL of a drafting plugin action.
   */
  private function actionUrl(string $action): string {
    return Url::fromRoute('oe_ai_assistant.plugin.dispatch', [
      'plugin_id' => 'drafting',
      'action' => $action,
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * Builds the add-document URL for a session, with the upload parameters.
   */
  private function uploadUrl(string|int $sessionId): string {
    return $this->actionUrl('add-document') . '?' . http_build_query([
      'sessionId' => (string) $sessionId,
      'category' => 'context',
      'filename' => 'brief.txt',
    ]);
  }

  /**
   * Asserts that the session references no context documents.
   */
  private function assertSessionHasNoDocuments(string $sessionId): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session');
    $storage->resetCache([$sessionId]);
    $this->assertTrue($storage->load($sessionId)->get('context_documents')->isEmpty());
  }

}
