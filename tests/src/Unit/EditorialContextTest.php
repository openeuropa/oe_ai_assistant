<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the EditorialContext value object and its provenance snapshot.
 *
 * The populated-documents case uses fixture descriptors of both categories
 * (context and publishable) to ensure snapshot wiring is proven before the
 * documents backend is fully implemented.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Service\Drafting\EditorialContext
 */
class EditorialContextTest extends UnitTestCase {

  /**
   * Tests the snapshot of a fully populated context.
   */
  public function testToSnapshotWithFullContext(): void {
    $documents = [
      [
        'id' => '12',
        'title' => 'Climate briefing note',
        'category' => 'context',
        'summary' => 'Key figures on EU emissions.',
        'meta' => ['mime' => 'application/pdf'],
      ],
      [
        'id' => '15',
        'title' => 'Hero image',
        'category' => 'publishable',
        'summary' => 'Wind turbines at sunset.',
        'meta' => ['mime' => 'image/png'],
      ],
    ];
    $context = new EditorialContext(
      toneId: '3',
      toneLabel: 'Formal',
      tonePrompt: 'Use professional, institutional language.',
      templateId: 'news_default',
      templateLabel: 'News default',
      documents: $documents,
    );

    $snapshot = $context->toSnapshot();

    $this->assertSame(['id' => '3', 'label' => 'Formal', 'prompt' => 'Use professional, institutional language.'], $snapshot['tone']);
    $this->assertSame(
      ['id' => 'news_default', 'label' => 'News default'],
      $snapshot['template'],
    );
    $this->assertSame($documents, $snapshot['documents']);
  }

  /**
   * Tests that missing tone and template snapshot as NULL, not as arrays.
   */
  public function testToSnapshotWithEmptyContext(): void {
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL);

    $snapshot = $context->toSnapshot();

    $this->assertNull($snapshot['tone']);
    $this->assertNull($snapshot['template']);
    $this->assertSame([], $snapshot['documents']);
  }

  /**
   * Tests that the prompt block is built from tone label and guidelines.
   */
  public function testToPromptWithFullContext(): void {
    $context = new EditorialContext(
      toneId: '3',
      toneLabel: 'Formal',
      tonePrompt: 'Use professional, institutional language.',
      templateId: 'news_default',
      templateLabel: 'News default',
    );

    $prompt = $context->toPrompt();

    $this->assertStringContainsString(
      'Editorial context selected by the editor for this draft:',
      $prompt,
    );
    $this->assertStringContainsString('- Tone: Formal', $prompt);
    $this->assertStringContainsString(
      '- Tone guidelines: Use professional, institutional language.',
      $prompt,
    );
    $this->assertStringContainsString(
      'Follow the tone guidelines',
      $prompt,
    );
  }

  /**
   * Tests that toPrompt returns an empty string when no tone is set.
   */
  public function testToPromptWithEmptyContext(): void {
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL);
    $this->assertSame('', $context->toPrompt());
    $this->assertSame('', $context->toDocumentsPrompt());
  }

  /**
   * Builds a context document descriptor.
   */
  private static function document(string $id, string $status, ?string $extract, string $summary = ''): array {
    return [
      'id' => $id,
      'title' => 'Document ' . $id,
      'category' => 'context',
      'status' => $status,
      'summary' => $summary,
      'meta' => ['type' => 'pdf', 'size' => 10],
      'extract' => $extract,
    ];
  }

  /**
   * Tests that processed documents are injected as titled text blocks.
   */
  public function testDocumentsPromptInjectsExtracts(): void {
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'done', 'Full text of one.', 'Summary one.'),
    ]);

    $prompt = $context->toDocumentsPrompt();

    $this->assertStringContainsString('Reference documents', $prompt);
    $this->assertStringContainsString("### Document 1
Full text of one.", $prompt);
    $this->assertStringNotContainsString('Summary one.', $prompt);
    $this->assertStringContainsString('background only', $prompt);
    $this->assertStringNotContainsString('wait a moment', $prompt);
    // Documents alone make a prompt, tone or not.
    $this->assertSame($prompt, $context->toPrompt());
  }

  /**
   * Tests that unprocessed and failed documents are announced, not read.
   */
  public function testDocumentsPromptAnnouncesPendingDocuments(): void {
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'extracting', NULL),
      self::document('2', 'error', NULL),
      self::document('3', 'error', 'Kept text.'),
    ]);

    $prompt = $context->toDocumentsPrompt();

    $this->assertStringContainsString("### Document 1
Not processed yet", $prompt);
    $this->assertStringContainsString("### Document 2
Processing failed", $prompt);
    $this->assertStringContainsString("### Document 3
Kept text.", $prompt);
    $this->assertStringContainsString('wait a moment', $prompt);
  }

  /**
   * Tests the per-document and total caps on injected text.
   */
  public function testDocumentsPromptCapsText(): void {
    $long = str_repeat('a', EditorialContext::MAX_DOCUMENT_CHARS + 10);
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'done', $long),
    ]);
    $prompt = $context->toDocumentsPrompt();
    $this->assertStringContainsString(str_repeat('a', EditorialContext::MAX_DOCUMENT_CHARS) . "
[truncated]", $prompt);
    $this->assertStringNotContainsString(str_repeat('a', EditorialContext::MAX_DOCUMENT_CHARS + 1), $prompt);

    // Once the total budget is spent, later documents fall back to summary.
    $count = intdiv(EditorialContext::MAX_TOTAL_CHARS, EditorialContext::MAX_DOCUMENT_CHARS) + 1;
    $documents = [];
    for ($i = 1; $i <= $count; $i++) {
      $documents[] = self::document((string) $i, 'done', str_repeat('b', EditorialContext::MAX_DOCUMENT_CHARS), 'Summary ' . $i);
    }
    $prompt = (new EditorialContext(NULL, NULL, NULL, NULL, NULL, $documents))->toDocumentsPrompt();
    $this->assertStringContainsString("### Document $count
Summary only: Summary $count", $prompt);
    $this->assertStringContainsString("### Document 1
bbb", $prompt);
  }

  /**
   * Tests that the tone block and the documents block are both in toPrompt.
   */
  public function testToPromptCombinesToneAndDocuments(): void {
    $context = new EditorialContext('3', 'Formal', 'Be formal.', NULL, NULL, [
      self::document('1', 'done', 'Text.'),
    ]);
    $prompt = $context->toPrompt();
    $this->assertStringContainsString('- Tone: Formal', $prompt);
    $this->assertStringContainsString("### Document 1
Text.", $prompt);
  }

  /**
   * Tests that the snapshot never carries the extracted text.
   */
  public function testSnapshotStripsExtracts(): void {
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'done', 'Full text.', 'Summary.'),
    ]);
    $snapshot = $context->toSnapshot();
    $this->assertArrayNotHasKey('extract', $snapshot['documents'][0]);
    $this->assertSame('Summary.', $snapshot['documents'][0]['summary']);
    $this->assertSame('done', $snapshot['documents'][0]['status']);
  }

}
