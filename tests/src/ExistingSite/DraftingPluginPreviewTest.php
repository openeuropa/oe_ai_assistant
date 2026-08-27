<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Integration tests for the DraftingPlugin preview action.
 *
 * Sends real HTTP POST requests to /api/ai/plugins/drafting/preview and
 * verifies the HTML response, that no node is ever persisted, and every
 * error path from the design doc's error table.
 */
class DraftingPluginPreviewTest extends DraftingPluginTestBase {

  /**
   * Seeds a versioned draft_content result directly on the transcript.
   *
   * Bypasses the chat/orchestrator flow entirely: preview only reads
   * DraftHistory's stored results, so seeding them directly keeps this
   * suite independent of the (separately tested) drafting conversation flow.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param int $version
   *   The draft version.
   * @param array $fields
   *   The drafted fields map.
   * @param string|null $templateId
   *   The template id to snapshot in context, or NULL for none.
   */
  protected function seedVersionedDraft(
    AiEditorialSessionInterface $session,
    int $version,
    array $fields,
    ?string $templateId,
  ): void {
    $storage = \Drupal::entityTypeManager()->getStorage('ai_conversation_message');
    $message = $storage->create([
      'host_entity_type' => $session->getEntityTypeId(),
      'host_entity_id' => (int) $session->id(),
      'role' => 'assistant',
      'content' => '',
    ]);
    $message->setToolCalls([
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content', 'arguments' => '{}'],
        'result' => [
          'version' => $version,
          'context' => [
            'tone' => NULL,
            'template' => $templateId !== NULL ? ['id' => $templateId, 'label' => $templateId] : NULL,
            'documents' => [],
          ],
          'fields' => $fields,
        ],
      ],
    ]);
    $message->save();
  }

  /**
   * Seeds a legacy (pre-provenance, unwrapped) draft_content result.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param array $fields
   *   The drafted fields map — stored bare, with no version/context wrapper.
   */
  protected function seedLegacyDraft(AiEditorialSessionInterface $session, array $fields): void {
    $storage = \Drupal::entityTypeManager()->getStorage('ai_conversation_message');
    $message = $storage->create([
      'host_entity_type' => $session->getEntityTypeId(),
      'host_entity_id' => (int) $session->id(),
      'role' => 'assistant',
      'content' => '',
    ]);
    $message->setToolCalls([
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content', 'arguments' => '{}'],
        'result' => $fields,
      ],
    ]);
    $message->save();
  }

  /**
   * Counts existing nodes, to assert preview never creates one.
   */
  protected function countNodes(): int {
    return (int) \Drupal::entityTypeManager()->getStorage('node')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Renders a themed HTML document and never persists a node.
   *
   * Also exercises the template-defaults merge: field_teaser is absent from
   * the seeded draft's fields but present in news_preview_defaults'
   * defaults, so it must still appear in the rendered output.
   */
  public function testPreviewRendersHtmlWithoutPersistingAndMergesDefaults(): void {
    $user = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->drupalLogin($user);

    $session = $this->createSession($user);
    $this->seedVersionedDraft(
      $session,
      1,
      ['title' => [['value' => 'Preview Test Title']]],
      'news_preview_defaults',
    );

    $nodesBefore = $this->countNodes();

    $result = $this->httpPost('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(200, $result['status'], 'Expected 200. Body: ' . substr($result['body'], 0, 2000));
    $this->assertMatchesRegularExpression('/<!DOCTYPE/i', $result['body'], 'Expected a full HTML document, not a fragment.');
    $this->assertMatchesRegularExpression('/<html[\s>]/i', $result['body'], 'Expected a full HTML document, not a fragment.');
    $this->assertStringContainsString('Preview Test Title', $result['body']);
    $this->assertStringContainsString('Default teaser from template.', $result['body']);
    $this->assertEquals($nodesBefore, $this->countNodes(), 'Preview must not persist a node.');
  }

  /**
   * A legacy (unwrapped) draft renders best-effort, without defaults.
   */
  public function testPreviewRendersLegacyDraftBestEffort(): void {
    $user = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->drupalLogin($user);

    $session = $this->createSession($user);
    $this->seedLegacyDraft($session, ['title' => [['value' => 'Legacy Draft Title']]]);

    $result = $this->httpPost('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(200, $result['status'], 'Expected 200. Body: ' . substr($result['body'], 0, 2000));
    $this->assertStringContainsString('Legacy Draft Title', $result['body']);
  }

  /**
   * Missing create permission is a 403.
   */
  public function testPreviewPermissionDenied(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);

    $session = $this->createSession($user);
    $this->seedVersionedDraft($session, 1, ['title' => [['value' => 'x']]], NULL);

    $result = $this->httpPost('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(403, $result['status']);
    $this->assertEquals('forbidden', json_decode($result['body'], TRUE)['code']);
  }

  /**
   * A version with no stored draft is a 404.
   */
  public function testPreviewVersionNotFound(): void {
    $user = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->drupalLogin($user);

    $session = $this->createSession($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(404, $result['status']);
    $this->assertEquals('invalid_request', json_decode($result['body'], TRUE)['code']);
  }

  /**
   * A snapshot template id that no longer resolves is a 400.
   */
  public function testPreviewInvalidSnapshotTemplate(): void {
    $user = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->drupalLogin($user);

    $session = $this->createSession($user);
    $this->seedVersionedDraft(
      $session,
      1,
      ['title' => [['value' => 'x']]],
      'template_does_not_exist',
    );

    $result = $this->httpPost('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(400, $result['status']);
    $this->assertEquals('invalid_request', json_decode($result['body'], TRUE)['code']);
  }

}
