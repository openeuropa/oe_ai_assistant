<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the EditorialContext value object and its provenance snapshot.
 *
 * The populated case uses fixture context document descriptors to prove
 * the snapshot wiring.
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
        'status' => 'done',
        'meta' => ['type' => 'pdf', 'size' => 1024],
        'category' => 'context',
        'summary' => 'Key figures on EU emissions.',
      ],
      [
        'id' => '15',
        'title' => 'Programme factsheet',
        'status' => 'done',
        'meta' => ['type' => 'docx', 'size' => 2048],
        'category' => 'context',
        'summary' => 'Funding lines and deadlines.',
      ],
    ];
    $groups = [
      [
        'groupId' => 'main_fields',
        'label' => 'Main fields',
        'fieldNames' => ['title'],
        'schemaSlice' => ['type' => 'object'],
      ],
    ];
    $context = new EditorialContext(
      toneId: '3',
      toneLabel: 'Formal',
      tonePrompt: 'Use professional, institutional language.',
      templateId: 'news_default',
      templateLabel: 'News default',
      contextDocuments: $documents,
      groups: $groups,
    );

    $snapshot = $context->toSnapshot();

    $this->assertSame(['id' => '3', 'label' => 'Formal', 'prompt' => 'Use professional, institutional language.'], $snapshot['tone']);
    $this->assertSame(
      ['id' => 'news_default', 'label' => 'News default'],
      $snapshot['template'],
    );
    $this->assertSame($documents, $snapshot['documents']);
    $this->assertSame($groups, $snapshot['groups'],
      'The groups the draft is written against travel with it.');
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
    $this->assertSame([], $snapshot['groups']);
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
    $this->assertSame('', $context->toContextDocumentsPrompt());
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
      'filename' => 'doc-' . $id . '.pdf',
      'summary' => $summary,
      'meta' => ['type' => 'pdf', 'size' => 10],
      'extract' => $extract,
    ];
  }

  /**
   * Tests that processed documents are injected as titled text blocks.
   *
   * The heading carries the file name so the agents can tell documents
   * apart when the editor refers to one by name.
   */
  public function testDocumentsPromptInjectsExtracts(): void {
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'done', 'Full text of one.', 'Summary one.'),
    ]);

    $prompt = $context->toContextDocumentsPrompt();

    $this->assertStringContainsString('Context documents', $prompt);
    $this->assertStringContainsString("### Document 1 (file: doc-1.pdf)
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

    $prompt = $context->toContextDocumentsPrompt();

    $this->assertStringContainsString("### Document 1 (file: doc-1.pdf)
Not processed yet", $prompt);
    $this->assertStringContainsString("### Document 2 (file: doc-2.pdf)
Processing failed", $prompt);
    $this->assertStringContainsString("### Document 3 (file: doc-3.pdf)
Kept text.", $prompt);
    $this->assertStringContainsString('wait a moment', $prompt);
    $this->assertStringContainsString('retry them', $prompt);

    // Waiting never helps a failed document: alone, it only asks for a retry.
    $prompt = (new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'error', NULL),
    ]))->toContextDocumentsPrompt();
    $this->assertStringContainsString('retry them', $prompt);
    $this->assertStringNotContainsString('wait a moment', $prompt);

    // A pending document alone never asks for a retry.
    $prompt = (new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'scheduled', NULL),
    ]))->toContextDocumentsPrompt();
    $this->assertStringContainsString('wait a moment', $prompt);
    $this->assertStringNotContainsString('retry them', $prompt);
  }

  /**
   * Tests the per-document and total caps on injected text.
   */
  public function testDocumentsPromptCapsText(): void {
    $long = str_repeat('a', EditorialContext::MAX_DOCUMENT_CHARS + 10);
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'done', $long),
    ]);
    $prompt = $context->toContextDocumentsPrompt();
    $this->assertStringContainsString(str_repeat('a', EditorialContext::MAX_DOCUMENT_CHARS) . "
[truncated]", $prompt);
    $this->assertStringNotContainsString(str_repeat('a', EditorialContext::MAX_DOCUMENT_CHARS + 1), $prompt);

    // Once the total budget is spent, later documents fall back to summary.
    $count = intdiv(EditorialContext::MAX_TOTAL_CHARS, EditorialContext::MAX_DOCUMENT_CHARS) + 1;
    $documents = [];
    for ($i = 1; $i <= $count; $i++) {
      $documents[] = self::document((string) $i, 'done', str_repeat('b', EditorialContext::MAX_DOCUMENT_CHARS), 'Summary ' . $i);
    }
    $prompt = (new EditorialContext(NULL, NULL, NULL, NULL, NULL, $documents))->toContextDocumentsPrompt();
    $this->assertStringContainsString("### Document $count (file: doc-$count.pdf)
Summary only: Summary $count", $prompt);
    $this->assertStringContainsString("### Document 1 (file: doc-1.pdf)
bbb", $prompt);

    // A document that does not fit in the remaining budget also falls back
    // to summary, so the total never exceeds the budget.
    $documents = [];
    for ($i = 1; $i <= $count; $i++) {
      $documents[] = self::document((string) $i, 'done', str_repeat('x', EditorialContext::MAX_DOCUMENT_CHARS - 1), 'Summary ' . $i);
    }
    $prompt = (new EditorialContext(NULL, NULL, NULL, NULL, NULL, $documents))->toContextDocumentsPrompt();
    $this->assertStringContainsString("### Document $count (file: doc-$count.pdf)
Summary only: Summary $count", $prompt);
    $this->assertLessThanOrEqual(EditorialContext::MAX_TOTAL_CHARS, substr_count($prompt, 'x'));

    // Test the scenario where every document is longer than the per-document
    // cap, and there are exactly as many as the total budget can hold.
    $documents = [];
    $fitting = intdiv(EditorialContext::MAX_TOTAL_CHARS, EditorialContext::MAX_DOCUMENT_CHARS);
    for ($i = 1; $i <= $fitting; $i++) {
      $documents[] = self::document((string) $i, 'done', str_repeat('~', EditorialContext::MAX_DOCUMENT_CHARS + 10), 'Summary ' . $i);
    }
    $prompt = (new EditorialContext(NULL, NULL, NULL, NULL, NULL, $documents))->toContextDocumentsPrompt();
    // The budget counts document text only, not the truncation marker, so
    // every document is injected truncated.
    $this->assertSame($fitting, substr_count($prompt, "\n[truncated]"));
    // None of them is pushed out to its summary by the markers of the others.
    $this->assertStringNotContainsString('Summary only:', $prompt);
    // The injected text fills the budget exactly. The tilde appears nowhere
    // else in the prompt, so counting it counts the document text.
    $this->assertSame(EditorialContext::MAX_TOTAL_CHARS, substr_count($prompt, '~'));
  }

  /**
   * Tests over-budget documents that have no summary to fall back on.
   */
  public function testDocumentsPromptWithoutSummaryToFallBackOn(): void {
    // The summary only exists once the pipeline is done, so a document that
    // is extracted, being summarized, or failed while summarizing has text
    // but no summary yet.
    // Prepare a series of documents that fill the whole context budget.
    $filler = [];
    $documentCount = intdiv(EditorialContext::MAX_TOTAL_CHARS, EditorialContext::MAX_DOCUMENT_CHARS);
    for ($i = 1; $i <= $documentCount; $i++) {
      $filler[] = self::document((string) $i, 'done', str_repeat('b', EditorialContext::MAX_DOCUMENT_CHARS), 'Summary ' . $i);
    }
    $last = $documentCount + 1;

    // Test the scenario where an additional document is still in the pipeline
    // and its summary is not there yet.
    foreach (['extracted', 'summarizing'] as $status) {
      $prompt = (new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
        ...$filler,
        // An extra document with defined content but empty summary.
        self::document((string) $last, $status, 'Overflow text.'),
      ]))->toContextDocumentsPrompt();
      // The document is still announced under its heading.
      $this->assertStringContainsString("### Document $last (file: doc-$last.pdf)", $prompt, $status);
      // Its text does not fit and there is no summary to replace it, so no
      // summary line is printed. The filler documents all fit, so none of
      // them prints one either.
      $this->assertStringNotContainsString('Summary only:', $prompt, $status);
      // The budget is spent: the text stays out.
      $this->assertStringNotContainsString('Overflow text.', $prompt, $status);
      // The summary is on its way, so the editor is asked to wait, not to
      // retry.
      $this->assertStringContainsString('wait a moment', $prompt, $status);
      $this->assertStringNotContainsString('retry them', $prompt, $status);
    }

    // Test the scenario where the additional document failed while being
    // summarized, so its summary never comes without a retry.
    $prompt = (new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      ...$filler,
      // An extra document with defined content, empty summary and error status.
      self::document((string) $last, 'error', 'Overflow text.'),
    ]))->toContextDocumentsPrompt();
    // Same as above: announced, no summary line, text left out.
    $this->assertStringContainsString("### Document $last (file: doc-$last.pdf)", $prompt);
    $this->assertStringNotContainsString('Summary only:', $prompt);
    $this->assertStringNotContainsString('Overflow text.', $prompt);
    // Waiting does not help here, so the editor is asked to retry instead.
    $this->assertStringContainsString('retry them', $prompt);
    $this->assertStringNotContainsString('wait a moment', $prompt);

    // Test the scenario where the same failed document is alone, so the
    // budget is free and its text fits.
    $prompt = (new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'error', 'Overflow text.'),
    ]))->toContextDocumentsPrompt();
    // The text is injected despite the error status.
    $this->assertStringContainsString("### Document 1 (file: doc-1.pdf)
Overflow text.", $prompt);
    // Its content is available, so there is nothing to retry.
    $this->assertStringNotContainsString('retry them', $prompt);
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
    $this->assertStringContainsString("### Document 1 (file: doc-1.pdf)
Text.", $prompt);
  }

  /**
   * Tests that the snapshot never carries the file name or extracted text.
   */
  public function testSnapshotStripsExtracts(): void {
    $context = new EditorialContext(NULL, NULL, NULL, NULL, NULL, [
      self::document('1', 'done', 'Full text.', 'Summary.'),
    ]);
    $snapshot = $context->toSnapshot();
    $this->assertArrayNotHasKey('extract', $snapshot['documents'][0]);
    $this->assertArrayNotHasKey('filename', $snapshot['documents'][0]);
    $this->assertSame('Summary.', $snapshot['documents'][0]['summary']);
    $this->assertSame('done', $snapshot['documents'][0]['status']);
  }

}
