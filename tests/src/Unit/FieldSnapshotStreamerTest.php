<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\FieldSnapshotStreamer;
use Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter;
use PHPUnit\Framework\TestCase;
use Swis\AgUiServer\Events\AgUiEvent;
use Swis\AgUiServer\Events\StateDeltaEvent;
use Swis\AgUiServer\Events\StateSnapshotEvent;

/**
 * Tests the three-phase streaming lifecycle of FieldSnapshotStreamer.
 *
 * Uses a spy transporter to capture emitted events and verify
 * the sequence: skeleton snapshot, per-word deltas, final snapshot.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\FieldSnapshotStreamer
 */
class FieldSnapshotStreamerTest extends TestCase {

  /**
   * Captured events from the spy transporter.
   *
   * @var \Swis\AgUiServer\Events\AgUiEvent[]
   */
  private array $events = [];

  /**
   * Creates a mock transporter that captures sent events.
   */
  private function createSpyTransporter(): DrupalSseTransporter {
    $transporter = $this->createMock(DrupalSseTransporter::class);
    $transporter->method('sendEvent')
      ->willReturnCallback(function (AgUiEvent $event): void {
        $this->events[] = $event;
      });
    return $transporter;
  }

  /**
   * Tests three-phase streaming with a plain string field.
   *
   * @covers ::stream
   */
  public function testPlainStringFieldEmitsSkeletonDeltasFinal(): void {
    $transporter = $this->createSpyTransporter();
    $streamer = new FieldSnapshotStreamer($transporter);

    $fields = [
      'body' => 'The quick brown fox jumps over the lazy dog and keeps on running far away',
    ];
    $fieldIndex = [
      'body' => ['widget' => 'textarea'],
    ];

    $streamer->stream($fields, $fieldIndex, [], TRUE);

    // Must have at least 3 events: skeleton + N deltas + final.
    $this->assertGreaterThanOrEqual(3, count($this->events));

    // First event: skeleton snapshot with empty body.
    $first = $this->events[0];
    $this->assertInstanceOf(StateSnapshotEvent::class, $first);
    $firstData = $first->toArray();
    $this->assertSame('', $firstData['snapshot']['draftedFields']['body']);

    // Middle events: all deltas with replace operations.
    $deltas = array_slice($this->events, 1, -1);
    foreach ($deltas as $delta) {
      $this->assertInstanceOf(StateDeltaEvent::class, $delta);
      $deltaData = $delta->toArray();
      $this->assertSame('replace', $deltaData['delta'][0]['op']);
      $this->assertSame('/draftedFields/body', $deltaData['delta'][0]['path']);
    }

    // Last delta should carry the full text.
    $lastDelta = end($deltas);
    $lastDeltaData = $lastDelta->toArray();
    $this->assertSame(
      $fields['body'],
      $lastDeltaData['delta'][0]['value'],
    );

    // Final event: complete snapshot.
    $last = end($this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $last);
    $lastData = $last->toArray();
    $this->assertSame(
      $fields['body'],
      $lastData['snapshot']['draftedFields']['body'],
    );
  }

  /**
   * Tests three-phase streaming with a formatted text field.
   *
   * @covers ::stream
   */
  public function testFormattedTextFieldEmitsSkeletonDeltasFinal(): void {
    $transporter = $this->createSpyTransporter();
    $streamer = new FieldSnapshotStreamer($transporter);

    $fields = [
      'body' => [
        'value' => 'The quick brown fox jumps over the lazy dog and keeps on running far away',
        'format' => 'full_html',
        'summary' => '',
      ],
    ];
    $fieldIndex = [
      'body' => ['widget' => 'textarea_formatted'],
    ];

    $streamer->stream($fields, $fieldIndex, [], TRUE);

    // First event: skeleton with empty value, format preserved.
    $first = $this->events[0];
    $this->assertInstanceOf(StateSnapshotEvent::class, $first);
    $firstData = $first->toArray();
    $skeleton = $firstData['snapshot']['draftedFields']['body'];
    $this->assertSame('', $skeleton['value']);
    $this->assertSame('full_html', $skeleton['format']);

    // Middle events: deltas targeting /draftedFields/body/value.
    $deltas = array_slice($this->events, 1, -1);
    foreach ($deltas as $delta) {
      $this->assertInstanceOf(StateDeltaEvent::class, $delta);
      $deltaData = $delta->toArray();
      $this->assertSame(
        '/draftedFields/body/value',
        $deltaData['delta'][0]['path'],
      );
    }

    // Final event: complete snapshot with full value.
    $last = end($this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $last);
    $lastData = $last->toArray();
    $this->assertSame(
      $fields['body']['value'],
      $lastData['snapshot']['draftedFields']['body']['value'],
    );
    $this->assertSame(
      'full_html',
      $lastData['snapshot']['draftedFields']['body']['format'],
    );
  }

  /**
   * Tests that short/non-progressive fields appear in skeleton only.
   *
   * @covers ::stream
   */
  public function testNonProgressiveFieldInSkeletonOnly(): void {
    $transporter = $this->createSpyTransporter();
    $streamer = new FieldSnapshotStreamer($transporter);

    $fields = [
      'title' => 'Short title',
    ];
    $fieldIndex = [
      'title' => ['widget' => 'textfield'],
    ];

    $streamer->stream($fields, $fieldIndex, [], TRUE);

    // Should emit exactly 2 events: skeleton + final snapshot.
    $this->assertCount(2, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[1]);

    // Both should carry the final title value.
    $firstData = $this->events[0]->toArray();
    $this->assertSame(
      'Short title',
      $firstData['snapshot']['draftedFields']['title'],
    );
  }

  /**
   * Tests mixed progressive and non-progressive fields.
   *
   * @covers ::stream
   */
  public function testMixedFieldsEmitCorrectEventSequence(): void {
    $transporter = $this->createSpyTransporter();
    $streamer = new FieldSnapshotStreamer($transporter);

    $fields = [
      'title' => 'My Title',
      'body' => 'The quick brown fox jumps over the lazy dog and keeps on running far away',
    ];
    $fieldIndex = [
      'title' => ['widget' => 'textfield'],
      'body' => ['widget' => 'textarea'],
    ];

    $streamer->stream($fields, $fieldIndex, [], TRUE);

    // First event: skeleton with title=final, body=empty.
    $first = $this->events[0];
    $this->assertInstanceOf(StateSnapshotEvent::class, $first);
    $firstData = $first->toArray();
    $this->assertSame(
      'My Title',
      $firstData['snapshot']['draftedFields']['title'],
    );
    $this->assertSame(
      '',
      $firstData['snapshot']['draftedFields']['body'],
    );

    // Middle events: all deltas for body only.
    $deltas = array_slice($this->events, 1, -1);
    $this->assertNotEmpty($deltas);
    foreach ($deltas as $delta) {
      $this->assertInstanceOf(StateDeltaEvent::class, $delta);
    }

    // Final event: complete snapshot.
    $last = end($this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $last);
    $lastData = $last->toArray();
    $this->assertSame(
      'My Title',
      $lastData['snapshot']['draftedFields']['title'],
    );
    $this->assertSame(
      $fields['body'],
      $lastData['snapshot']['draftedFields']['body'],
    );
  }

}
