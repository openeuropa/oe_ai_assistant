<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\SessionConversation;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;

/**
 * Tests persisted inline edits, validation, provenance and preview/save parity.
 */
class DraftingPluginEditFieldTest extends DraftingPluginTestBase {

  /**
   * Seeds a draft without invoking an AI provider.
   */
  private function seedEditableDraft(AiEditorialSessionInterface $session, array $fields, ?string $template = NULL): void {
    parent::seedDraft($session, 1, $fields, [
      'template' => $template === NULL ? NULL : ['id' => $template, 'label' => $template],
    ]);
  }

  /**
   * Posts one scalar edit and returns its HTTP response.
   */
  private function edit(AiEditorialSessionInterface $session, string $field, array $path, mixed $value, int $revision = 0): array {
    return $this->httpPost('/api/ai/plugins/drafting/edit-field', [
      'sessionId' => $session->id(),
      'version' => 1,
      'field' => $field,
      'path' => $path,
      'value' => $value,
      'revision' => $revision,
    ]);
  }

  /**
   * An edit reaches hydration, preview and save while preserving AI output.
   */
  public function testEditPersistsWithoutSavingNode(): void {
    $user = $this->createUser([
      'use oe ai assistant', 'create oe_news content',
      'edit own oe_news content', 'use editorial transition create_new_draft',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $this->seedEditableDraft($session, [
      'title' => [['value' => 'Generated title']],
      'field_teaser' => [['value' => 'Keep this teaser.']],
    ], 'news_default');

    $read = $this->httpPost('/api/ai/plugins/drafting/get-draft', ['sessionId' => $session->id(), 'version' => 1]);
    $this->assertSame(200, $read['status'], $read['body']);
    $metadata = json_decode($read['body'], TRUE);
    $this->assertSame('Title', $metadata['schema']['properties']['title']['title']);
    $this->assertSame(0, $metadata['revision']);

    $edited = $this->edit($session, 'title', [0, 'value'], 'Edited title');
    $this->assertSame(200, $edited['status'], $edited['body']);
    $body = json_decode($edited['body'], TRUE);
    $this->assertSame(1, $body['revision']);
    $this->assertSame('Edited title', $body['fields']['title'][0]['value']);
    $this->assertSame('Keep this teaser.', $body['fields']['field_teaser'][0]['value']);
    $sessionStorage = \Drupal::entityTypeManager()->getStorage('ai_editorial_session');
    $sessionStorage->resetCache();
    $this->assertNull($sessionStorage->load($session->id())->getNode(), 'Applying an edit must not create a node.');

    $original = NULL;
    $changes = [];
    foreach ($this->loadTranscript($session) as $message) {
      $meta = SessionConversation::decode($message, 'meta');
      if (($meta['type'] ?? '') !== 'tool_call_result') {
        continue;
      }
      foreach ($meta['tools'] ?? [] as $call) {
        $result = SessionConversation::decodeResult($call['result'] ?? NULL);
        if (isset($result['draft'])) {
          $original = $result['draft']['fields']['title'][0]['value'];
          $changes = $meta['draft_edits'][1]['changes'] ?? [];
        }
      }
    }
    $this->assertSame('Generated title', $original, 'Generated output remains intact.');
    $this->assertCount(1, $changes);
    $messages = $this->getMessages($session);
    $draftMessages = array_values(array_filter($messages, static fn (array $message): bool => isset($message['toolCalls'])));
    $this->assertSame('Edited title', $draftMessages[0]['toolCalls'][0]['result']['draft']['fields']['title'][0]['value']);
    $preview = $this->httpGet('/api/ai/plugins/drafting/preview', ['sessionId' => $session->id(), 'version' => 1]);
    $this->assertSame(200, $preview['status'], $preview['body']);
    $this->assertStringContainsString('Edited title', $preview['body']);

    $saved = $this->approveSave($session, 1);
    $this->assertArrayHasKey('nodeId', $saved);
    $this->assertSame(1, $saved['editRevision']);
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($saved['nodeId']);
    $this->markEntityForCleanup($node);
    $this->assertSame('Edited title', $node->label());
    $this->assertFalse($node->isPublished());

    $editedAgain = $this->edit($session, 'title', [0, 'value'], 'Edited after save', 1);
    $this->assertSame(200, $editedAgain['status'], $editedAgain['body']);
    $savedAgain = $this->approveSave($session, 1, 'call_save_again');
    $this->assertSame((string) $node->id(), $savedAgain['nodeId']);
    $this->assertSame(2, $savedAgain['editRevision']);
    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
    $nodeStorage->resetCache();
    $latest = $nodeStorage->loadRevision($nodeStorage->getLatestRevisionId($node->id()));
    $this->assertSame('Edited after save', $latest->label());
    $this->assertFalse($latest->isPublished());
  }

  /**
   * Bad types, stale edits and protected or unknown fields cannot be written.
   */
  public function testRejectedEditsDoNotChangeDraft(): void {
    $user = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $this->seedEditableDraft($session, ['title' => [['value' => 'Original']], 'field_teaser' => [['value' => 'Pinned']]], 'news_preview_defaults');

    foreach ([
      ['title', [0, 'value'], 42, 400],
      ['title', [0, 'value'], str_repeat('x', 256), 400],
      ['title', [1, 'value'], 'Extra item', 400],
      ['title', [0, 'format'], 'full_html', 400],
      ['uid', [0, 'target_id'], 1, 400],
      ['field_teaser', [0, 'value'], 'Changed default', 403],
    ] as [$field, $path, $value, $status]) {
      $response = $this->edit($session, $field, $path, $value);
      $this->assertSame($status, $response['status'], $response['body']);
    }
    $accepted = $this->edit($session, 'title', [0, 'value'], 'Updated');
    $this->assertSame(200, $accepted['status'], $accepted['body']);
    $stale = $this->edit($session, 'title', [0, 'value'], 'Stale update');
    $this->assertSame(409, $stale['status'], $stale['body']);
    $unchanged = $this->edit($session, 'title', [0, 'value'], 'Updated', 1);
    $this->assertSame(200, $unchanged['status'], $unchanged['body']);
    $this->assertSame(1, json_decode($unchanged['body'], TRUE)['revision'], 'Applying an unchanged value is a no-op.');
  }

  /**
   * Nested edits preserve paragraph types, sibling items and text formats.
   */
  public function testNestedAndEmptyValues(): void {
    $user = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $this->seedEditableDraft($session, [
      'title' => [['value' => 'Paragraph test']],
      'field_teaser' => [],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'quote_block']],
          'field_quote_text' => [['value' => 'Quote']],
          'field_quote_attribution' => [['value' => 'Original author']],
        ],
        ['type' => [['target_id' => 'text_block']], 'field_text_body' => [['value' => 'Keep this paragraph']]],
      ],
    ], 'news_with_paragraphs');

    $nested = $this->edit($session, 'field_content_paragraphs', [0, 'field_quote_attribution', 0, 'value'], 'Edited author');
    $this->assertSame(200, $nested['status'], $nested['body']);
    $fields = json_decode($nested['body'], TRUE)['fields'];
    $this->assertSame('Edited author', $fields['field_content_paragraphs'][0]['field_quote_attribution'][0]['value']);
    $this->assertSame('Keep this paragraph', $fields['field_content_paragraphs'][1]['field_text_body'][0]['value']);
    $protected = $this->edit($session, 'field_content_paragraphs', [0, 'type', 0, 'target_id'], 'text_block', 1);
    $this->assertSame(400, $protected['status'], $protected['body']);
    $empty = $this->edit($session, 'field_teaser', [0, 'value'], 'Filled empty field', 1);
    $this->assertSame(200, $empty['status'], $empty['body']);
    $this->assertSame('Filled empty field', json_decode($empty['body'], TRUE)['fields']['field_teaser'][0]['value']);
  }

  /**
   * Dates, selects, booleans and formatted text retain their Drupal types.
   */
  public function testTypedValues(): void {
    $user = $this->createUser([
      'use oe ai assistant', 'create oe_news content',
      'administer nodes', 'use text format full_html',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $this->seedEditableDraft($session, [
      'title' => [['value' => 'Typed values']],
      'field_publication_date' => [['value' => '2026-10-27']],
      'field_publication_datetime' => [['value' => '2026-10-27T10:00:00Z']],
      'field_news_type' => [['value' => 'announcement']],
      'promote' => [['value' => FALSE]],
      'field_body' => [['value' => '<p>Original body</p>', 'format' => 'full_html', 'summary' => 'Keep summary']],
    ]);
    $invalid = $this->edit($session, 'field_news_type', [0, 'value'], 'not-an-option');
    $this->assertSame(400, $invalid['status'], $invalid['body']);
    $invalid = $this->edit($session, 'promote', [0, 'value'], 'true');
    $this->assertSame(400, $invalid['status'], $invalid['body']);
    $edits = [
      ['field_publication_date', '2026-11-02'],
      ['field_publication_datetime', '2026-11-02T09:15:00Z'],
      ['field_news_type', 'update'],
      ['promote', TRUE],
      ['field_body', '<p>Edited <strong>body</strong></p>'],
    ];
    foreach ($edits as $revision => [$field, $value]) {
      $response = $this->edit($session, $field, [0, 'value'], $value, $revision);
      $this->assertSame(200, $response['status'], $response['body']);
      $body = json_decode($response['body'], TRUE);
      $this->assertSame($value, $body['fields'][$field][0]['value']);
    }
    $this->assertSame('full_html', $body['fields']['field_body'][0]['format']);
    $this->assertSame('Keep summary', $body['fields']['field_body'][0]['summary']);
    $this->assertSame('richtext', $body['schema']['properties']['field_body']['items']['properties']['value']['x-editor']);
    $cleared = $this->edit($session, 'field_publication_date', [0, 'value'], NULL, 5);
    $this->assertSame(200, $cleared['status'], $cleared['body']);
    $this->assertNull(json_decode($cleared['body'], TRUE)['fields']['field_publication_date'][0]['value']);
    $required = $this->edit($session, 'title', [0, 'value'], NULL, 6);
    $this->assertSame(400, $required['status'], $required['body']);
    $summary = $this->edit($session, 'field_body', [0, 'summary'], 'Edited summary', 6);
    $this->assertSame(200, $summary['status'], $summary['body']);
    $this->assertSame('Edited summary', json_decode($summary['body'], TRUE)['fields']['field_body'][0]['summary']);
  }

  /**
   * A session owner without content permission and another user are denied.
   */
  public function testEditAccess(): void {
    $owner = $this->createUser(['use oe ai assistant']);
    $this->loginUser($owner);
    $session = $this->createSession($owner);
    $this->seedEditableDraft($session, ['title' => [['value' => 'Original']]], 'news_default');
    $response = $this->edit($session, 'title', [0, 'value'], 'No permission');
    $this->assertSame(403, $response['status'], $response['body']);

    $other = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->loginUser($other);
    $response = $this->edit($session, 'title', [0, 'value'], 'Wrong session');
    $this->assertSame(403, $response['status'], $response['body']);
  }

  /**
   * Neuron revises the manually edited values and keeps its grouped numbering.
   */
  public function testNeuronRevisionStartsFromManualEdits(): void {
    $user = $this->createUser(['use oe ai assistant', 'create oe_news content']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $this->seedEditableDraft($session, [
      'title' => [['value' => 'Generated title']],
      'field_teaser' => [['value' => 'Original teaser']],
    ], 'news_default');
    $edited = $this->edit($session, 'title', [0, 'value'], 'Manual headline');
    $this->assertSame(200, $edited['status'], $edited['body']);
    MockAiProvider::enqueue(new MockResponse(toolCalls: [[
      'id' => 'call_revision',
      'type' => 'function',
      'function' => [
        'name' => 'revise_draft',
        'arguments' => json_encode([
          'instruction' => 'Rewrite the teaser and retain the title.',
          'groups' => ['main_fields'],
          'version' => 1,
        ]),
      ],
    ],
    ]));
    MockAiProvider::enqueue(new MockResponse(text: json_encode([
      'title' => [['value' => 'Manual headline']],
      'field_teaser' => [['value' => 'Revised teaser']],
      'field_body' => [],
    ])));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1.1 is ready.'));
    $response = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'sessionId' => $session->id(),
      'message' => 'Rewrite the teaser and retain the manually edited title.',
    ]);
    $this->assertSame(200, $response['status'], $response['body']);
    $history = \Drupal::service(DraftHistoryInterface::class);
    $revision = $history->getDraftContent($session, 2);
    $this->assertNotNull($revision, $response['body']);
    $this->assertSame('Draft 1.1', $revision['name']);
    $this->assertSame('Manual headline', $revision['fields']['title'][0]['value']);
    \Drupal::state()->resetCache();
    $drafterCalls = array_filter(MockAiProvider::getCallLog(), static fn (array $call): bool => str_contains($call['system_prompt'], 'You are a content generator'));
    $this->assertCount(1, $drafterCalls);
    $this->assertStringContainsString('Manual headline', json_encode(array_values($drafterCalls)[0]['messages']));
  }

}
