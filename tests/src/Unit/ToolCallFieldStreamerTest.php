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
 * Tests incremental streaming of tool call argument deltas.
 *
 * ToolCallFieldStreamer receives partial JSON from the LLM's streaming
 * tool call arguments, repairs the incomplete JSON, diffs against the
 * previous state, and emits STATE_DELTA events for changed fields.
 *
 * Uses a spy transporter to capture emitted events.
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
   * Resets captured events before each test.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->events = [];
  }

  /**
   * Creates a mock transporter that captures sent events.
   *
   * @return \Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter
   *   A mock transporter whose sendEvent() stores events in $this->events.
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
   * Tests that emitInitialSnapshot sends an empty state only once.
   *
   * Calling emitInitialSnapshot() twice should produce exactly one
   * StateSnapshotEvent because the method is idempotent (guarded by
   * the $initialized flag).
   *
   * @covers ::emitInitialSnapshot
   */
  public function testEmitInitialSnapshotSendsEmptyStateOnce(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = ['title' => ['widget' => 'textfield']];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    $streamer->emitInitialSnapshot();
    $streamer->emitInitialSnapshot();

    // Only one event should be emitted despite two calls.
    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
    $data = $this->events[0]->toArray();
    $this->assertSame([], $data['snapshot']['draftedFields']);
  }

  /**
   * Tests that onDelta lazily emits the initial snapshot on first call.
   *
   * The first onDelta() call should emit both the initial snapshot
   * and the delta event (2 events total).
   *
   * @covers ::onDelta
   */
  public function testOnDeltaEmitsInitialSnapshotLazily(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = ['title' => ['widget' => 'textfield']];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    // First onDelta should trigger initial snapshot + delta.
    $streamer->onDelta('{"title": "Hello"}');

    // Expect 2 events: initial snapshot + delta.
    $this->assertCount(2, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
    $this->assertInstanceOf(StateDeltaEvent::class, $this->events[1]);
  }

  /**
   * Tests that garbage input produces only the initial snapshot.
   *
   * When the partial JSON is completely unparseable, the streamer
   * should emit the initial snapshot (lazily) but no delta events.
   *
   * @covers ::onDelta
   */
  public function testGarbageInputEmitsNoEvents(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = ['title' => ['widget' => 'textfield']];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    $streamer->onDelta('not json at all');

    // Only the initial snapshot, no delta.
    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
  }

  /**
   * Tests that an empty input string produces only the initial snapshot.
   *
   * @covers ::onDelta
   */
  public function testEmptyInputEmitsNoDeltas(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = ['title' => ['widget' => 'textfield']];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    $streamer->onDelta('');

    // Only the initial snapshot, no delta.
    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
  }

  /**
   * Tests progressive streaming of a plain string field.
   *
   * Simulates incremental tool call arguments where the title grows
   * from a partial string to the full value. The first appearance
   * should emit an "add" operation, and subsequent changes should
   * emit "replace" operations.
   *
   * @covers ::onDelta
   */
  public function testPlainStringFieldStreamsIncrementally(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = ['title' => ['widget' => 'textfield']];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    // First chunk: partial title.
    $streamer->onDelta('{"title": "EU L"}');
    // Second chunk: title grows.
    $streamer->onDelta('{"title": "EU Legislation Update"}');

    // Events: initial snapshot + add delta + replace delta.
    $this->assertCount(3, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);

    // First delta: "add" for the new field.
    $this->assertInstanceOf(StateDeltaEvent::class, $this->events[1]);
    $delta1 = $this->events[1]->toArray();
    $this->assertSame('add', $delta1['delta'][0]['op']);
    $this->assertSame('/draftedFields/title', $delta1['delta'][0]['path']);
    $this->assertSame('EU L', $delta1['delta'][0]['value']);

    // Second delta: "replace" for the updated value.
    $this->assertInstanceOf(StateDeltaEvent::class, $this->events[2]);
    $delta2 = $this->events[2]->toArray();
    $this->assertSame('replace', $delta2['delta'][0]['op']);
    $this->assertSame('/draftedFields/title', $delta2['delta'][0]['path']);
    $this->assertSame('EU Legislation Update', $delta2['delta'][0]['value']);
  }

  /**
   * Tests that formatted text fields target the /value sub-key.
   *
   * When a field value is an associative array with a "value" key
   * (formatted text), the JSON Patch path should include /value
   * to target only the text content.
   *
   * @covers ::onDelta
   */
  public function testFormattedTextFieldTargetsValueSubkey(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = ['body' => ['widget' => 'textarea_formatted']];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    // First appearance: adds the whole field object.
    $streamer->onDelta('{"body": {"value": "Hello world"}}');

    // Events: initial snapshot + add delta.
    $this->assertCount(2, $this->events);
    $delta = $this->events[1]->toArray();
    $this->assertSame('add', $delta['delta'][0]['op']);
    // First "add" targets the whole field path (not /value) so
    // the parent object exists for subsequent replace ops.
    $this->assertSame('/draftedFields/body', $delta['delta'][0]['path']);
    $this->assertSame(
      ['value' => 'Hello world'],
      $delta['delta'][0]['value'],
    );

    // Second call: value grows, should "replace" on /value subkey.
    $streamer->onDelta('{"body": {"value": "Hello world and more"}}');

    $this->assertCount(3, $this->events);
    $delta2 = $this->events[2]->toArray();
    $this->assertSame('replace', $delta2['delta'][0]['op']);
    $this->assertSame(
      '/draftedFields/body/value', $delta2['delta'][0]['path'],
    );
    $this->assertSame(
      'Hello world and more', $delta2['delta'][0]['value'],
    );
  }

  /**
   * Tests that phantom fields not in the index are filtered out.
   *
   * When json_repair_decode produces keys that do not exist in the
   * field index (e.g. "titl" from incomplete JSON), those keys
   * should be silently discarded.
   *
   * @covers ::onDelta
   */
  public function testPhantomFieldsFilteredByFieldIndex(): void {
    $transporter = $this->createSpyTransporter();
    // Only "title" is in the index.
    $fieldIndex = ['title' => ['widget' => 'textfield']];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    // "titl" is a phantom key not in the field index.
    $streamer->onDelta('{"titl": "partial"}');

    // Only initial snapshot, no delta (phantom filtered out).
    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
  }

  /**
   * Tests that no delta is emitted when content is unchanged.
   *
   * Sending the same JSON twice should produce only one delta event
   * (from the first call). The second call detects no changes and
   * emits nothing.
   *
   * @covers ::onDelta
   */
  public function testNoDeltaWhenUnchanged(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = ['title' => ['widget' => 'textfield']];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    $streamer->onDelta('{"title": "Same value"}');
    $streamer->onDelta('{"title": "Same value"}');

    // Events: initial snapshot + 1 delta (second call has no changes).
    $this->assertCount(2, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
    $this->assertInstanceOf(StateDeltaEvent::class, $this->events[1]);
  }

  /**
   * Tests that multiple fields appear progressively in deltas.
   *
   * Simulates a scenario where the title appears first, then the
   * body appears in a later chunk. Each new field should produce
   * its own delta event.
   *
   * @covers ::onDelta
   */
  public function testMultipleFieldsAppearProgressively(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = [
      'title' => ['widget' => 'textfield'],
      'body' => ['widget' => 'textarea'],
    ];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    // First chunk: only title.
    $streamer->onDelta('{"title": "News"}');
    // Second chunk: title + body.
    $streamer->onDelta('{"title": "News", "body": "Breaking news content"}');

    // Events: initial snapshot + title add + body add (title unchanged).
    $this->assertCount(3, $this->events);

    // First delta: title appears.
    $delta1 = $this->events[1]->toArray();
    $this->assertSame('add', $delta1['delta'][0]['op']);
    $this->assertSame('/draftedFields/title', $delta1['delta'][0]['path']);
    $this->assertSame('News', $delta1['delta'][0]['value']);

    // Second delta: body appears (title unchanged, not included).
    $delta2 = $this->events[2]->toArray();
    $this->assertCount(1, $delta2['delta']);
    $this->assertSame('add', $delta2['delta'][0]['op']);
    $this->assertSame('/draftedFields/body', $delta2['delta'][0]['path']);
    $this->assertSame('Breaking news content', $delta2['delta'][0]['value']);
  }

  /**
   * Tests that emitFinalSnapshot sends the complete state.
   *
   * @covers ::emitFinalSnapshot
   */
  public function testEmitFinalSnapshotSendsCompleteState(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = [
      'title' => ['widget' => 'textfield'],
      'body' => ['widget' => 'textarea'],
    ];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    $fields = [
      'title' => 'Final Title',
      'body' => 'Final body content',
    ];
    $streamer->emitFinalSnapshot($fields);

    $this->assertCount(1, $this->events);
    $this->assertInstanceOf(StateSnapshotEvent::class, $this->events[0]);
    $data = $this->events[0]->toArray();
    $this->assertSame($fields, $data['snapshot']['draftedFields']);
  }

  /**
   * Tests that fields are normalized when the wrapper key is absent.
   *
   * When the LLM emits tool call arguments without the "fields" wrapper
   * (e.g. {"title": "..."} instead of {"fields": {"title": "..."}}),
   * onDelta should still process them correctly.
   *
   * @covers ::onDelta
   */
  public function testFieldsNormalizedWithoutWrapper(): void {
    $transporter = $this->createSpyTransporter();
    $fieldIndex = ['title' => ['widget' => 'textfield']];
    $streamer = new ToolCallFieldStreamer($transporter, $fieldIndex);

    // No "fields" wrapper -- direct field map.
    $streamer->onDelta('{"title": "Direct"}');

    $this->assertCount(2, $this->events);
    $delta = $this->events[1]->toArray();
    $this->assertSame('add', $delta['delta'][0]['op']);
    $this->assertSame('/draftedFields/title', $delta['delta'][0]['path']);
    $this->assertSame('Direct', $delta['delta'][0]['value']);
  }

}
