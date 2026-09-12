<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;

/**
 * Integration tests for the DraftingPlugin extract-document action.
 *
 * Runs against the real Tika service of the environment; only the chat
 * provider is mocked.
 */
class DraftingPluginExtractDocumentTest extends DraftingPluginTestBase {

  /**
   * Tests that an uploaded document is extracted and summarised.
   */
  public function testExtractDocumentProcessesUpload(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    MockAiProvider::enqueue(new MockResponse('Brief summary.'));

    $query = http_build_query(['sessionId' => $session->id(), 'category' => 'context', 'filename' => 'brief.txt']);
    $added = $this->httpPostRaw('/api/ai/plugins/drafting/add-document?' . $query, 'Extraction smoke test.');
    $this->assertSame(200, $added['status'], $added['body']);
    $document = json_decode($added['body'], TRUE)['document'];
    $this->assertSame('scheduled', $document['status']);

    $result = $this->httpPost('/api/ai/plugins/drafting/extract-document', [
      'sessionId' => $session->id(),
      'category' => 'context',
      'documentId' => $document['id'],
    ]);
    $this->assertSame(200, $result['status'], $result['body']);
    $this->assertSame(['status' => 'done'], json_decode($result['body'], TRUE));

    $listed = $this->httpPost('/api/ai/plugins/drafting/list-documents', [
      'sessionId' => $session->id(),
      'category' => 'context',
    ]);
    $this->assertSame('done', json_decode($listed['body'], TRUE)['documents'][0]['status']);

    $media = \Drupal::entityTypeManager()->getStorage('media')->load($document['id']);
    $this->markEntityForCleanup($media);
    $this->assertStringContainsString('Extraction smoke test', $media->get('oe_ai_document_extract')->value);
    $this->assertSame('Brief summary.', $media->get('oe_ai_document_summary')->value);
  }

  /**
   * Tests that a PDF goes through the Tika service of the environment.
   *
   * Smoke test of the DDEV Tika wiring: the PDF fixture must come back as
   * text, which no PHP code path can fake.
   */
  public function testExtractDocumentReadsPdfThroughTika(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    MockAiProvider::enqueue(new MockResponse('PDF summary.'));

    $query = http_build_query(['sessionId' => $session->id(), 'category' => 'context', 'filename' => 'sample.pdf']);
    $pdf = file_get_contents(__DIR__ . '/../../fixtures/sample.pdf');
    $added = $this->httpPostRaw('/api/ai/plugins/drafting/add-document?' . $query, $pdf);
    $this->assertSame(200, $added['status'], $added['body']);
    $document = json_decode($added['body'], TRUE)['document'];

    $result = $this->httpPost('/api/ai/plugins/drafting/extract-document', [
      'sessionId' => $session->id(),
      'category' => 'context',
      'documentId' => $document['id'],
    ]);
    $this->assertSame(['status' => 'done'], json_decode($result['body'], TRUE));

    $media = \Drupal::entityTypeManager()->getStorage('media')->load($document['id']);
    $this->markEntityForCleanup($media);
    $this->assertStringContainsString('Drupal', $media->get('oe_ai_document_extract')->value);
  }

  /**
   * Tests that a missing documentId is rejected by request validation.
   */
  public function testExtractDocumentRejectsMissingDocumentId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/extract-document', [
      'sessionId' => '1',
      'category' => 'context',
    ]);

    $this->assertSame(400, $result['status']);
    $body = json_decode($result['body'], TRUE);
    $this->assertSame('bad_request', $body['code']);
    $this->assertStringContainsString('documentId', $body['message']);
  }

}
