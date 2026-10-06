<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\oe_ai_assistant\Plugin\NeuronAgent\EditorialSessionAgentBase;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the prompt blocks an editorial session agent injects.
 *
 * The renderers read nothing, so what the editor set up is passed to them as
 * the pieces the brief answers with.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Plugin\NeuronAgent\EditorialSessionAgentBase
 */
class EditorialSessionAgentPromptsTest extends UnitTestCase {

  /**
   * Tests that the prompt block is built from tone label and guidelines.
   */
  public function testTonePromptCarriesLabelAndGuidelines(): void {
    $prompt = EditorialSessionAgentBase::tonePrompt([
      'id' => '3',
      'label' => 'Formal',
      'prompt' => 'Use professional, institutional language.',
    ]);

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
   * Tests that nothing set renders nothing.
   */
  public function testNothingSetRendersNothing(): void {
    $this->assertSame('', EditorialSessionAgentBase::tonePrompt(NULL));
    $this->assertSame('', EditorialSessionAgentBase::tonePrompt(['id' => '3', 'label' => 'Formal', 'prompt' => '']));
    $this->assertSame('', EditorialSessionAgentBase::documentsPrompt([]));
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
    $prompt = EditorialSessionAgentBase::documentsPrompt([
      self::document('1', 'done', 'Full text of one.', 'Summary one.'),
    ]);

    $this->assertStringContainsString('Context documents', $prompt);
    $this->assertStringContainsString("### Document 1 (file: doc-1.pdf)
Full text of one.", $prompt);
    $this->assertStringNotContainsString('Summary one.', $prompt);
    $this->assertStringContainsString('background only', $prompt);
    $this->assertStringNotContainsString('wait a moment', $prompt);
  }

  /**
   * Tests that unprocessed and failed documents are announced, not read.
   */
  public function testDocumentsPromptAnnouncesPendingDocuments(): void {
    $prompt = EditorialSessionAgentBase::documentsPrompt([
      self::document('1', 'extracting', NULL),
      self::document('2', 'error', NULL),
      self::document('3', 'error', 'Kept text.'),
    ]);

    $this->assertStringContainsString("### Document 1 (file: doc-1.pdf)
Not processed yet", $prompt);
    $this->assertStringContainsString("### Document 2 (file: doc-2.pdf)
Processing failed", $prompt);
    $this->assertStringContainsString("### Document 3 (file: doc-3.pdf)
Kept text.", $prompt);
    $this->assertStringContainsString('wait a moment', $prompt);
    $this->assertStringContainsString('retry them', $prompt);

    // Waiting never helps a failed document: alone, it only asks for a retry.
    $prompt = EditorialSessionAgentBase::documentsPrompt([
      self::document('1', 'error', NULL),
    ]);
    $this->assertStringContainsString('retry them', $prompt);
    $this->assertStringNotContainsString('wait a moment', $prompt);

    // A pending document alone never asks for a retry.
    $prompt = EditorialSessionAgentBase::documentsPrompt([
      self::document('1', 'scheduled', NULL),
    ]);
    $this->assertStringContainsString('wait a moment', $prompt);
    $this->assertStringNotContainsString('retry them', $prompt);
  }

  /**
   * Tests the per-document and total caps on injected text.
   */
  public function testDocumentsPromptCapsText(): void {
    $long = str_repeat('a', EditorialSessionAgentBase::MAX_DOCUMENT_CHARS + 10);
    $prompt = EditorialSessionAgentBase::documentsPrompt([
      self::document('1', 'done', $long),
    ]);
    $this->assertStringContainsString(str_repeat('a', EditorialSessionAgentBase::MAX_DOCUMENT_CHARS) . "
[truncated]", $prompt);
    $this->assertStringNotContainsString(str_repeat('a', EditorialSessionAgentBase::MAX_DOCUMENT_CHARS + 1), $prompt);

    // Once the total budget is spent, later documents fall back to summary.
    $count = intdiv(EditorialSessionAgentBase::MAX_TOTAL_CHARS, EditorialSessionAgentBase::MAX_DOCUMENT_CHARS) + 1;
    $documents = [];
    for ($i = 1; $i <= $count; $i++) {
      $documents[] = self::document((string) $i, 'done', str_repeat('b', EditorialSessionAgentBase::MAX_DOCUMENT_CHARS), 'Summary ' . $i);
    }
    $prompt = EditorialSessionAgentBase::documentsPrompt($documents);
    $this->assertStringContainsString("### Document $count (file: doc-$count.pdf)
Summary only: Summary $count", $prompt);
    $this->assertStringContainsString("### Document 1 (file: doc-1.pdf)
bbb", $prompt);

    // A document that does not fit in the remaining budget also falls back
    // to summary, so the total never exceeds the budget.
    $documents = [];
    for ($i = 1; $i <= $count; $i++) {
      $documents[] = self::document((string) $i, 'done', str_repeat('x', EditorialSessionAgentBase::MAX_DOCUMENT_CHARS - 1), 'Summary ' . $i);
    }
    $prompt = EditorialSessionAgentBase::documentsPrompt($documents);
    $this->assertStringContainsString("### Document $count (file: doc-$count.pdf)
Summary only: Summary $count", $prompt);
    $this->assertLessThanOrEqual(EditorialSessionAgentBase::MAX_TOTAL_CHARS, substr_count($prompt, 'x'));

    // Test the scenario where every document is longer than the per-document
    // cap, and there are exactly as many as the total budget can hold.
    $documents = [];
    $fitting = intdiv(EditorialSessionAgentBase::MAX_TOTAL_CHARS, EditorialSessionAgentBase::MAX_DOCUMENT_CHARS);
    for ($i = 1; $i <= $fitting; $i++) {
      $documents[] = self::document((string) $i, 'done', str_repeat('~', EditorialSessionAgentBase::MAX_DOCUMENT_CHARS + 10), 'Summary ' . $i);
    }
    $prompt = EditorialSessionAgentBase::documentsPrompt($documents);
    // The budget counts document text only, not the truncation marker, so
    // every document is injected truncated.
    $this->assertSame($fitting, substr_count($prompt, "\n[truncated]"));
    // None of them is pushed out to its summary by the markers of the others.
    $this->assertStringNotContainsString('Summary only:', $prompt);
    // The injected text fills the budget exactly. The tilde appears nowhere
    // else in the prompt, so counting it counts the document text.
    $this->assertSame(EditorialSessionAgentBase::MAX_TOTAL_CHARS, substr_count($prompt, '~'));
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
    $documentCount = intdiv(EditorialSessionAgentBase::MAX_TOTAL_CHARS, EditorialSessionAgentBase::MAX_DOCUMENT_CHARS);
    for ($i = 1; $i <= $documentCount; $i++) {
      $filler[] = self::document((string) $i, 'done', str_repeat('b', EditorialSessionAgentBase::MAX_DOCUMENT_CHARS), 'Summary ' . $i);
    }
    $last = $documentCount + 1;

    // Test the scenario where an additional document is still in the pipeline
    // and its summary is not there yet.
    foreach (['extracted', 'summarizing'] as $status) {
      $prompt = EditorialSessionAgentBase::documentsPrompt([
        ...$filler,
        // An extra document with defined content but empty summary.
        self::document((string) $last, $status, 'Overflow text.'),
      ]);
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
    $prompt = EditorialSessionAgentBase::documentsPrompt([
      ...$filler,
      // An extra document with defined content, empty summary and error status.
      self::document((string) $last, 'error', 'Overflow text.'),
    ]);
    // Same as above: announced, no summary line, text left out.
    $this->assertStringContainsString("### Document $last (file: doc-$last.pdf)", $prompt);
    $this->assertStringNotContainsString('Summary only:', $prompt);
    $this->assertStringNotContainsString('Overflow text.', $prompt);
    // Waiting does not help here, so the editor is asked to retry instead.
    $this->assertStringContainsString('retry them', $prompt);
    $this->assertStringNotContainsString('wait a moment', $prompt);

    // Test the scenario where the same failed document is alone, so the
    // budget is free and its text fits.
    $prompt = EditorialSessionAgentBase::documentsPrompt([
      self::document('1', 'error', 'Overflow text.'),
    ]);
    // The text is injected despite the error status.
    $this->assertStringContainsString("### Document 1 (file: doc-1.pdf)
Overflow text.", $prompt);
    // Its content is available, so there is nothing to retry.
    $this->assertStringNotContainsString('retry them', $prompt);
  }

}
