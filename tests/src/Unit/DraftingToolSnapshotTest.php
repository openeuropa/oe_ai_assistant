<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\oe_ai_assistant\Plugin\NeuronTool\DraftingToolBase;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the provenance snapshot a drafting tool stores on a draft.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Plugin\NeuronTool\DraftingToolBase
 */
class DraftingToolSnapshotTest extends UnitTestCase {

  /**
   * Tests the snapshot of everything the editor can set up.
   */
  public function testSnapshotOfEverythingSet(): void {
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
    $snapshot = DraftingToolBase::snapshot(
      ['id' => '3', 'label' => 'Formal', 'prompt' => 'Use professional, institutional language.'],
      ['id' => 'news_default', 'label' => 'News default'],
      $documents,
      $groups,
    );

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
  public function testSnapshotOfNothingSet(): void {
    $snapshot = DraftingToolBase::snapshot(NULL, NULL, [], []);

    $this->assertNull($snapshot['tone']);
    $this->assertNull($snapshot['template']);
    $this->assertSame([], $snapshot['documents']);
    $this->assertSame([], $snapshot['groups']);
  }

  /**
   * Tests that the snapshot never carries the file name or extracted text.
   */
  public function testSnapshotStripsExtracts(): void {
    $snapshot = DraftingToolBase::snapshot(NULL, NULL, [
      self::document('1', 'done', 'Full text.', 'Summary.'),
    ], []);
    $this->assertArrayNotHasKey('extract', $snapshot['documents'][0]);
    $this->assertArrayNotHasKey('filename', $snapshot['documents'][0]);
    $this->assertSame('Summary.', $snapshot['documents'][0]['summary']);
    $this->assertSame('done', $snapshot['documents'][0]['status']);
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

}
