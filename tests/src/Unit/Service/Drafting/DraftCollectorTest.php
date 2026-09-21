<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit\Service\Drafting;

use Drupal\oe_ai_assistant\Service\Drafting\DraftCollector;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the draft collector.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Service\Drafting\DraftCollector
 */
class DraftCollectorTest extends TestCase {

  /**
   * The groups of a news article: main fields, contacts and paragraphs.
   */
  private const GROUPS = [
    [
      'groupId' => 'main_fields',
      'label' => 'Main fields',
      'fieldNames' => ['title', 'field_teaser'],
      'schemaSlice' => [],
    ],
    [
      'groupId' => 'field_contacts',
      'label' => 'Contacts',
      'fieldNames' => ['field_contacts'],
      'schemaSlice' => [],
    ],
    [
      'groupId' => 'field_paragraphs',
      'label' => 'Paragraphs',
      'fieldNames' => ['field_paragraphs'],
      'schemaSlice' => [],
    ],
  ];

  /**
   * Builds a collector whose versioning wraps the fields as draft 3.
   */
  private function collector(): DraftCollector {
    return new DraftCollector(self::GROUPS, static fn (array $fields): array => [
      'version' => 3,
      'context' => [],
      'fields' => $fields,
    ]);
  }

  /**
   * @covers ::pending
   * @covers ::draft
   */
  public function testDraftIsVersionedOnceEveryGroupIsDrafted(): void {
    $collector = $this->collector();
    $this->assertSame(['main_fields', 'field_contacts', 'field_paragraphs'], $collector->pending());
    $this->assertNull($collector->draft());

    $collector->add('main_fields', [
      'title' => [['value' => 'T']],
      'field_teaser' => [['value' => 'S']],
      'body' => 'ignored',
    ]);
    $collector->add('field_paragraphs', [['type' => 'oe_text']]);
    $this->assertSame(['field_contacts'], $collector->pending());
    $this->assertNull($collector->draft());

    $collector->add('field_contacts', ['field_contacts' => [['target_uuid' => 'c1']]]);
    $this->assertSame([], $collector->pending());
    $this->assertSame([
      'version' => 3,
      'context' => [],
      'fields' => [
        'title' => [['value' => 'T']],
        'field_teaser' => [['value' => 'S']],
        'field_contacts' => [['target_uuid' => 'c1']],
        'field_paragraphs' => [['type' => 'oe_text']],
      ],
    ], $collector->draft());
    $this->assertSame($collector->draft(), $collector->draft(), 'The draft is versioned once.');
  }

  /**
   * @covers ::group
   * @covers ::mainFields
   */
  public function testGroupLookupAndMainFields(): void {
    $collector = $this->collector();
    $this->assertSame('Contacts', $collector->group('field_contacts')['label']);
    $this->assertNull($collector->group('nope'));
    $this->assertNull($collector->mainFields());
    $collector->add('main_fields', ['title' => [['value' => 'T']]]);
    $this->assertSame(['title' => [['value' => 'T']]], $collector->mainFields());
  }

}
