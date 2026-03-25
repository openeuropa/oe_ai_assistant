<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\ToolCallFieldStreamer;
use Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter;
use PHPUnit\Framework\TestCase;
use Swis\AgUiServer\Events\AgUiEvent;
use Swis\AgUiServer\Events\StateDeltaEvent;
use Swis\AgUiServer\Events\StateSnapshotEvent;

/**
 * Tests the ToolCallFieldStreamer class.
 *
 * Verifies field diffing and SSE event emission for streaming
 * decoded tool call arguments into AG-UI state.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\ToolCallFieldStreamer
 */
class ToolCallFieldStreamerTest extends TestCase {

  /**
   * Captured events from the spy transporter.
   *
   * @var \Swis\AgUiServer\Events\AgUiEvent[]
   */
  private array $events = [];

  /**
   * Creates a mock transporter that captures sent events.
   *
   * @return \Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter
   *   The spy transporter mock.
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->events = [];
  }

  /**
   * Tests that emitInitialSnapshot sends an empty state only once.
   *
   * Calling the method twice should still produce only a single
   * StateSnapshotEvent with an empty draftedFields array.
   *
   * @covers ::emitInitialSnapshot
   */
  public function testEmitInitialSnapshotSendsEmptyStateOnce(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE],
    );

    $streamer->emitInitialSnapshot();
    $streamer->emitInitialSnapshot();

    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);

    $data = $this->events[0]->toArray();
    $this->assertSame([], $data['snapshot']['draftedFields']);
  }

  /**
   * Tests that onDelta lazily emits the initial snapshot before the delta.
   *
   * The first call to onDelta should emit the initial snapshot followed
   * by the state delta, producing exactly two events.
   *
   * @covers ::onDelta
   */
  public function testOnDeltaEmitsInitialSnapshotLazily(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE],
    );

    $streamer->onDelta(['fields' => ['title' => 'Hello']]);

    $this->assertCount(2, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
    $this->assertInstanceOf(StateDeltaEvent::class, $this->events[1]);
  }

  /**
   * Tests that garbage input produces no delta events.
   *
   * Non-JSON input should still trigger the initial snapshot but
   * must not emit any delta events.
   *
   * @covers ::onDelta
   */
  public function testGarbageInputEmitsNoEvents(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE],
    );

    // Pass an array with no valid fields (not a field map).
    $streamer->onDelta(['not' => 'fields']);

    // Initial snapshot + 1 delta for "not" which is not in index.
    // Since "not" is not in fieldIndex, no delta is emitted.
    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
  }

  /**
   * Tests that empty input produces no delta events.
   *
   * An empty string should trigger the initial snapshot but must not
   * emit any delta events.
   *
   * @covers ::onDelta
   */
  public function testEmptyInputEmitsNoDeltas(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE],
    );

    // Empty array: no fields to process.
    $streamer->onDelta([]);

    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
  }

  /**
   * Tests incremental streaming of a plain string field.
   *
   * The first call should emit an "add" operation and the second
   * call should emit a "replace" operation for the same field.
   *
   * @covers ::onDelta
   */
  public function testPlainStringFieldStreamsIncrementally(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE],
    );

    // First chunk: partial title.
    $streamer->onDelta(['fields' => ['title' => 'Hel']]);
    // Second chunk: extended title.
    $streamer->onDelta(['fields' => ['title' => 'Hello world']]);

    // Initial snapshot + 2 deltas.
    $this->assertCount(3, $this->events);

    // First delta: "add" operation.
    $firstDelta = $this->events[1]->toArray();
    $this->assertSame('add', $firstDelta['delta'][0]['op']);
    $this->assertSame('/draftedFields/title', $firstDelta['delta'][0]['path']);
    $this->assertSame('Hel', $firstDelta['delta'][0]['value']);

    // Second delta: "replace" operation.
    $secondDelta = $this->events[2]->toArray();
    $this->assertSame('replace', $secondDelta['delta'][0]['op']);
    $this->assertSame('/draftedFields/title', $secondDelta['delta'][0]['path']);
    $this->assertSame('Hello world', $secondDelta['delta'][0]['value']);
  }

  /**
   * Tests formatted text field adds whole object then replaces value.
   *
   * The first call must emit an "add" with the complete object (not
   * just the value subkey) because JSON Patch cannot add to a
   * non-existent parent. The second call should emit a "replace"
   * targeting the /value subkey only.
   *
   * @covers ::onDelta
   */
  public function testFormattedTextFieldAddsWholeObjectThenReplacesValue(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['body' => TRUE],
    );

    // First chunk: body with formatted text object.
    $streamer->onDelta(['fields' => ['body' => ['value' => 'First', 'format' => 'full_html']]]);
    // Second chunk: updated body value.
    $streamer->onDelta(['fields' => ['body' => ['value' => 'First paragraph.', 'format' => 'full_html']]]);

    // Initial snapshot + 2 deltas.
    $this->assertCount(3, $this->events);

    // First delta: "add" with the WHOLE object.
    $firstDelta = $this->events[1]->toArray();
    $this->assertSame('add', $firstDelta['delta'][0]['op']);
    $this->assertSame('/draftedFields/body', $firstDelta['delta'][0]['path']);
    $this->assertSame(
      ['value' => 'First', 'format' => 'full_html'],
      $firstDelta['delta'][0]['value'],
    );

    // Second delta: "replace" on the /value subkey only.
    $secondDelta = $this->events[2]->toArray();
    $this->assertSame('replace', $secondDelta['delta'][0]['op']);
    $this->assertSame('/draftedFields/body/value', $secondDelta['delta'][0]['path']);
    $this->assertSame('First paragraph.', $secondDelta['delta'][0]['value']);
  }

  /**
   * Tests that phantom fields not in the index are filtered out.
   *
   * A field name that does not exist in the field index should not
   * produce any delta events.
   *
   * @covers ::onDelta
   */
  public function testPhantomFieldsFilteredByFieldIndex(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE],
    );

    // "titl" is not in the field index.
    $streamer->onDelta(['fields' => ['titl' => 'Hello']]);

    // Only the initial snapshot, no deltas.
    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
  }

  /**
   * Tests that unchanged content does not produce a duplicate delta.
   *
   * Sending the same content twice should emit only one delta event
   * plus the initial snapshot (2 events total).
   *
   * @covers ::onDelta
   */
  public function testNoDeltaWhenUnchanged(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE],
    );

    $streamer->onDelta(['fields' => ['title' => 'Same']]);
    $streamer->onDelta(['fields' => ['title' => 'Same']]);

    // Initial snapshot + 1 delta only.
    $this->assertCount(2, $this->events);
  }

  /**
   * Tests that multiple fields appear progressively.
   *
   * When a second field appears in the stream, only the new field
   * should be in the delta (the existing unchanged field should not
   * produce a duplicate operation).
   *
   * @covers ::onDelta
   */
  public function testMultipleFieldsAppearProgressively(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE, 'body' => TRUE],
    );

    // First chunk: only title.
    $streamer->onDelta(['fields' => ['title' => 'Hello']]);
    // Second chunk: title unchanged, body appears.
    $streamer->onDelta(['fields' => ['title' => 'Hello', 'body' => ['value' => 'Text', 'format' => 'html']]]);

    // Initial snapshot + 2 deltas.
    $this->assertCount(3, $this->events);

    // Second delta should only contain the "body" add operation.
    $secondDelta = $this->events[2]->toArray();
    $this->assertCount(1, $secondDelta['delta']);
    $this->assertSame('add', $secondDelta['delta'][0]['op']);
    $this->assertSame('/draftedFields/body', $secondDelta['delta'][0]['path']);
  }

  /**
   * Tests that emitFinalSnapshot sends a reconciliation snapshot.
   *
   * The final snapshot should contain the complete drafted fields
   * state, wrapped in the draftedFields key.
   *
   * @covers ::emitFinalSnapshot
   */
  public function testEmitFinalSnapshotSendsCompleteState(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE],
    );

    $fields = ['title' => 'Final title'];
    $streamer->emitFinalSnapshot($fields);

    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);

    $data = $this->events[0]->toArray();
    $this->assertSame(
      ['title' => 'Final title'],
      $data['snapshot']['draftedFields'],
    );
  }

  /**
   * Tests that fields without the "fields" wrapper key are normalized.
   *
   * When the tool call arguments do not contain a "fields" key, the
   * streamer should treat the entire decoded object as the fields map.
   *
   * @covers ::onDelta
   */
  public function testFieldsNormalizedWithoutWrapper(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyTransporter(),
      ['title' => TRUE],
    );

    // No "fields" wrapper.
    $streamer->onDelta(['title' => 'Direct']);

    // Initial snapshot + 1 delta.
    $this->assertCount(2, $this->events);

    $delta = $this->events[1]->toArray();
    $this->assertSame('add', $delta['delta'][0]['op']);
    $this->assertSame('/draftedFields/title', $delta['delta'][0]['path']);
    $this->assertSame('Direct', $delta['delta'][0]['value']);
  }

}
