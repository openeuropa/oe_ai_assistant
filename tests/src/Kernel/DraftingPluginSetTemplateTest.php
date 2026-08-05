<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the drafting set-template action.
 */
#[Group('oe_ai_assistant')]
class DraftingPluginSetTemplateTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_drafting_template');
    $storage->create([
      'id' => 'news_a',
      'label' => 'News A',
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'x']],
    ])->save();
    $storage->create([
      'id' => 'news_disabled',
      'label' => 'News disabled',
      'status' => FALSE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'x']],
    ])->save();
    $storage->create([
      'id' => 'contact_a',
      'label' => 'Contact A',
      'content_type' => 'oe_contact',
      'fields' => ['title' => ['prompt' => 'x']],
    ])->save();
  }

  /**
   * Runs the set-template action.
   */
  private function setTemplate(string $sessionId, string $template): array {
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');
    $request = Request::create('/', 'POST', content: json_encode([
      'sessionId' => $sessionId,
      'template' => $template,
    ]));
    return $plugin->executeAction('set-template', $request);
  }

  /**
   * Creates an oe_news session owned by the acting user.
   */
  private function ownedSession(): string {
    $owner = $this->createUser(['use oe ai assistant', 'view_update own sessions']);
    $this->container->get('current_user')->setAccount($owner);
    return (string) $this->createSession($owner)->id();
  }

  /**
   * Reloads the stored template id for a session.
   */
  private function storedTemplate(string $sessionId): ?string {
    $session = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')->loadUnchanged($sessionId);
    return $session->get('template')->target_id;
  }

  /**
   * A valid template is persisted on the session.
   */
  public function testSetTemplatePersistsOnSession(): void {
    $sessionId = $this->ownedSession();

    $result = $this->setTemplate($sessionId, 'news_a');

    $this->assertSame(['status' => 'ok'], $result);
    $this->assertSame('news_a', $this->storedTemplate($sessionId));
  }

  /**
   * A disabled template is rejected.
   */
  public function testDisabledTemplateIsRejected(): void {
    $sessionId = $this->ownedSession();
    $this->expectException(ActionException::class);
    $this->expectExceptionMessage('This entity (<em class="placeholder">ai_drafting_template</em>: <em class="placeholder">news_disabled</em>) cannot be referenced.');
    $this->setTemplate($sessionId, 'news_disabled');
  }

  /**
   * A template for another bundle is rejected.
   */
  public function testWrongBundleTemplateIsRejected(): void {
    $sessionId = $this->ownedSession();
    $this->expectException(ActionException::class);
    $this->expectExceptionMessage('This entity (<em class="placeholder">ai_drafting_template</em>: <em class="placeholder">contact_a</em>) cannot be referenced.');
    $this->setTemplate($sessionId, 'contact_a');
  }

  /**
   * A missing template id is rejected.
   */
  public function testMissingTemplateIsRejected(): void {
    $sessionId = $this->ownedSession();
    $this->expectException(ActionException::class);
    $this->expectExceptionMessage('The referenced entity (<em class="placeholder">ai_drafting_template</em>: <em class="placeholder">does_not_exist</em>) does not exist.');
    $this->setTemplate($sessionId, 'does_not_exist');
  }

  /**
   * An empty template is rejected because the field is mandatory.
   */
  public function testEmptyTemplateIsRejected(): void {
    $sessionId = $this->ownedSession();
    $this->expectException(ActionException::class);
    $this->expectExceptionMessage('This value should not be null.');
    $this->setTemplate($sessionId, '');
  }

  /**
   * A stale stored template makes chat() return an error.
   */
  public function testChatWithStaleSessionTemplateReturnsError(): void {
    $sessionId = $this->ownedSession();
    $this->setTemplate($sessionId, 'news_a');
    // Delete the selected template; the session keeps a dangling id.
    $this->container->get('entity_type.manager')
      ->getStorage('ai_drafting_template')->load('news_a')->delete();

    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');
    $request = Request::create('/', 'POST', content: json_encode([
      'sessionId' => $sessionId,
      'message' => 'Draft it.',
    ]));

    $this->expectException(ActionException::class);
    $this->expectExceptionMessage('Drafting template "news_a" not found.');
    $plugin->executeAction('chat', $request);
  }

}
