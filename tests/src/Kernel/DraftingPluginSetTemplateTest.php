<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Response\SseResponse;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
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
    $this->enableModules(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', [
        'chat' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
        'chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
        'chat_with_structured_response' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
      ])
      ->save();
    MockAiProvider::reset();

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

  /**
   * The chat() action streams via SseResponse.
   *
   * SseResponse::sendContent() drains every PHP output buffer before
   * sending, so events reach the wire as the agent produces them rather
   * than batched into one block at the end of the request (PHP's default
   * output_buffering holds everything a callback echoes otherwise).
   */
  public function testChatStreamsViaSseResponse(): void {
    $sessionId = $this->ownedSession();
    $this->setTemplate($sessionId, 'news_a');
    MockAiProvider::enqueue(new MockResponse(text: 'Hello.'));

    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');
    $request = Request::create('/', 'POST', content: json_encode([
      'sessionId' => $sessionId,
      'message' => 'Hi.',
    ]));

    $response = $plugin->chat($request);

    $this->assertInstanceOf(SseResponse::class, $response);
  }

  /**
   * A mid-stream provider failure degrades to a clean error event.
   *
   * The agent's stream has already started (a start event is on the wire)
   * by the time the provider call fails, so the callback cannot simply
   * throw: Drupal's exception handler would print an HTML error page into
   * the open SSE response. It must log the failure and write a protocol
   * error event instead.
   */
  public function testChatStreamFailureDegradesToErrorEvent(): void {
    $sessionId = $this->ownedSession();
    $this->setTemplate($sessionId, 'news_a');
    // No response queued: the provider throws on its first call, after the
    // agent has already emitted its start event.
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');
    $request = Request::create('/', 'POST', content: json_encode([
      'sessionId' => $sessionId,
      'message' => 'Hi.',
    ]));

    $response = $plugin->chat($request);

    // The channel flushes each frame out of the buffer it was written to, so
    // the callback runs inside two: the inner one receives the frames and the
    // outer one collects what the flush pushes out of it.
    ob_start();
    ob_start();
    ($response->getCallback())();
    ob_end_flush();
    $output = ob_get_clean();

    $this->assertStringNotContainsString(
      'MockAiProvider',
      $output,
      'The internal failure detail must never leak into the stream.',
    );
    $this->assertStringContainsString('"type":"error"', $output);
  }

  /**
   * A provider misconfiguration must not be mistaken for a stale template.
   *
   * The session has a valid template, but the chat_with_tools operation
   * type has no provider configured; createAgent() throws the same SPL
   * \InvalidArgumentException class ai_neuron's ProviderFactory uses.
   * chat()'s stale-template catch must not swallow it into a leaked 400 —
   * a site misconfiguration is not something the editor caused, and its
   * raw message can carry internals a 400 response must never repeat.
   */
  public function testChatProviderMisconfigurationIsNotMistakenForStaleTemplate(): void {
    $sessionId = $this->ownedSession();
    $this->setTemplate($sessionId, 'news_a');
    $this->config('ai.settings')
      ->set('default_providers.chat_with_tools', ['provider_id' => '', 'model_id' => ''])
      ->save();

    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');
    $request = Request::create('/', 'POST', content: json_encode([
      'sessionId' => $sessionId,
      'message' => 'Hi.',
    ]));

    $this->expectException(\InvalidArgumentException::class);
    $plugin->executeAction('chat', $request);
  }

}
