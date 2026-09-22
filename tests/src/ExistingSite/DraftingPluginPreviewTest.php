<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

/**
 * Integration tests for the DraftingPlugin preview action.
 *
 * Sends real HTTP GET requests to /api/ai/plugins/drafting/preview and
 * verifies the HTML response, that no node is ever persisted, and every
 * error path from the design doc's error table.
 */
class DraftingPluginPreviewTest extends DraftingPluginTestBase {

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
    $this->seedDraft(
      $session,
      1,
      ['title' => [['value' => 'Preview Test Title']]],
      ['template' => ['id' => 'news_preview_defaults', 'label' => 'news_preview_defaults']],
    );

    $nodesBefore = $this->countNodes();

    $result = $this->httpGet('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(200, $result['status'], 'Expected 200. Body: ' . substr($result['body'], 0, 2000));
    $this->assertMatchesRegularExpression('/<!DOCTYPE/i', $result['body'], 'Expected a full HTML document, not a fragment.');
    $this->assertMatchesRegularExpression('/<html[\s>]/i', $result['body'], 'Expected a full HTML document, not a fragment.');
    $this->assertStringContainsString('Preview Test Title', $result['body']);
    $this->assertStringContainsString('Default teaser from template.', $result['body']);
    $this->assertMatchesRegularExpression('/field__label[^>]*>\s*Teaser\s*</', $result['body'], 'Field labels must be displayed in the preview.');
    $this->assertEquals($nodesBefore, $this->countNodes(), 'Preview must not persist a node.');
  }

  /**
   * A template default overrides the drafted value for the same field.
   */
  public function testPreviewTemplateDefaultsOverrideDraftedFields(): void {
    $user = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->drupalLogin($user);

    $session = $this->createSession($user);
    $this->seedDraft(
      $session,
      1,
      [
        'title' => [['value' => 'Override Test Title']],
        'field_teaser' => [['value' => 'Drafted teaser.']],
      ],
      ['template' => ['id' => 'news_preview_defaults', 'label' => 'news_preview_defaults']],
    );

    $result = $this->httpGet('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(200, $result['status'], 'Expected 200. Body: ' . substr($result['body'], 0, 2000));
    $this->assertStringContainsString('Default teaser from template.', $result['body']);
    $this->assertStringNotContainsString('Drafted teaser.', $result['body'], 'Template defaults must override drafted values.');
  }

  /**
   * Each version renders its own content; responses are never cached.
   *
   * The preview is a GET, so without an uncacheable response Drupal's
   * dynamic page cache would replay the first rendered version for
   * every later version query on the same route.
   */
  public function testPreviewIsNotCachedAcrossVersions(): void {
    $user = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->drupalLogin($user);

    $session = $this->createSession($user);
    $this->seedDraft($session, 1, ['title' => [['value' => 'First Version Title']]]);
    $this->seedDraft($session, 2, ['title' => [['value' => 'Second Version Title']]]);

    $first = $this->httpGet('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);
    $second = $this->httpGet('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 2,
    ]);

    $this->assertEquals(200, $first['status']);
    $this->assertStringContainsString('First Version Title', $first['body']);
    $this->assertEquals(200, $second['status']);
    $this->assertStringContainsString('Second Version Title', $second['body']);
    $this->assertStringNotContainsString('First Version Title', $second['body'], 'A cached response for another version must never be replayed.');
  }

  /**
   * Missing create permission is a 403.
   */
  public function testPreviewPermissionDenied(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->drupalLogin($user);

    $session = $this->createSession($user);
    $this->seedDraft($session, 1, ['title' => [['value' => 'x']]]);

    $result = $this->httpGet('/api/ai/plugins/drafting/preview', [
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

    $result = $this->httpGet('/api/ai/plugins/drafting/preview', [
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
    $this->seedDraft(
      $session,
      1,
      ['title' => [['value' => 'x']]],
      ['template' => ['id' => 'template_does_not_exist', 'label' => 'template_does_not_exist']],
    );

    $result = $this->httpGet('/api/ai/plugins/drafting/preview', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertEquals(400, $result['status']);
    $this->assertEquals('invalid_request', json_decode($result['body'], TRUE)['code']);
  }

}
