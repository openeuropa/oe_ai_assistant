<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\ToolCallFieldStreamer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ToolCallFieldStreamer class.
 *
 * Verifies field diffing and data stream event emission for streaming
 * decoded tool call arguments into frontend drafted-fields state.
 *
 * @coversDefaultClass \Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\ToolCallFieldStreamer
 */
class ToolCallFieldStreamerTest extends TestCase {

  /**
   * Shared container for events captured by the spy writer.
   *
   * An ArrayObject is used so the anonymous subclass can append to it
   * without needing PHP reference-typed properties (which are not
   * supported). Test assertions read from this container directly.
   *
   * @var \ArrayObject
   */
  private \ArrayObject $spyContainer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->spyContainer = new \ArrayObject();
  }

  /**
   * Creates a spy emitter closure that captures calls.
   *
   * @return \Closure
   *   Emitter with signature fn(string $type, array $data): void.
   */
  private function createSpyEmitter(): \Closure {
    $container = $this->spyContainer;
    return function (string $type, array $data = []) use ($container): void {
      $data['type'] = $type;
      $container->append($data);
    };
  }

  /**
   * Returns the list of events captured by the spy writer.
   *
   * @return array<int, array<string, mixed>>
   *   Indexed array of event payloads.
   */
  private function capturedEvents(): array {
    return $this->spyContainer->getArrayCopy();
  }

  /**
   * Tests that emitInitialSnapshot sends an empty state only once.
   *
   * Calling the method twice should still produce only a single
   * data-drafted-fields event with an empty data object and
   * transient: true.
   *
   * @covers ::emitInitialSnapshot
   */
  public function testEmitInitialSnapshotSendsEmptyStateOnce(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyEmitter(),
      ['title' => TRUE],
    );

    $streamer->emitInitialSnapshot();
    $streamer->emitInitialSnapshot();

    $events = $this->capturedEvents();
    $this->assertCount(1, $events);
    $this->assertSame('data-drafted-fields', $events[0]['type']);
    $this->assertTrue($events[0]['transient']);
    $this->assertEquals((object) [], $events[0]['data']);
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
      $this->createSpyEmitter(),
      ['title' => TRUE],
    );

    $streamer->onDelta(['fields' => ['title' => 'Hello']]);

    $events = $this->capturedEvents();
    $this->assertCount(2, $events);

    // First event: initial empty snapshot (transient).
    $this->assertSame('data-drafted-fields', $events[0]['type']);
    $this->assertTrue($events[0]['transient']);
    $this->assertEquals((object) [], $events[0]['data']);

    // Second event: delta with full field state (transient).
    $this->assertSame('data-drafted-fields', $events[1]['type']);
    $this->assertTrue($events[1]['transient']);
    $this->assertArrayHasKey('title', $events[1]['data']);
  }

  /**
   * Tests that garbage input produces no delta events.
   *
   * Non-field input should still trigger the initial snapshot but
   * must not emit any delta events.
   *
   * @covers ::onDelta
   */
  public function testGarbageInputEmitsNoEvents(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyEmitter(),
      ['title' => TRUE],
    );

    // Pass an array with no valid fields (not in field index).
    $streamer->onDelta(['not' => 'fields']);

    $events = $this->capturedEvents();

    // Only the initial snapshot; "not" is not in fieldIndex so no delta.
    $this->assertCount(1, $events);
    $this->assertSame('data-drafted-fields', $events[0]['type']);
    $this->assertTrue($events[0]['transient']);
  }

  /**
   * Tests that empty input produces no delta events.
   *
   * An empty array should trigger the initial snapshot but must not
   * emit any delta events.
   *
   * @covers ::onDelta
   */
  public function testEmptyInputEmitsNoDeltas(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyEmitter(),
      ['title' => TRUE],
    );

    // Empty array: no fields to process.
    $streamer->onDelta([]);

    $events = $this->capturedEvents();
    $this->assertCount(1, $events);
    $this->assertSame('data-drafted-fields', $events[0]['type']);
    $this->assertTrue($events[0]['transient']);
  }

  /**
   * Tests incremental streaming of a plain string field.
   *
   * Each call with a changed value should emit a transient
   * data-drafted-fields event with the full accumulated fields state.
   *
   * @covers ::onDelta
   */
  public function testPlainStringFieldStreamsIncrementally(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyEmitter(),
      ['title' => TRUE],
    );

    // First chunk: partial title.
    $streamer->onDelta(['fields' => ['title' => 'Hel']]);
    // Second chunk: extended title.
    $streamer->onDelta(['fields' => ['title' => 'Hello world']]);

    $events = $this->capturedEvents();

    // Initial snapshot + 2 deltas.
    $this->assertCount(3, $events);

    // First delta: full state with partial title (transient).
    $this->assertSame('data-drafted-fields', $events[1]['type']);
    $this->assertTrue($events[1]['transient']);
    $this->assertSame('Hel', $events[1]['data']['title']);

    // Second delta: full state with extended title (transient).
    $this->assertSame('data-drafted-fields', $events[2]['type']);
    $this->assertTrue($events[2]['transient']);
    $this->assertSame('Hello world', $events[2]['data']['title']);
  }

  /**
   * Tests formatted text field emits the full object in each delta.
   *
   * Each delta payload should contain the full current field state,
   * including the formatted text object with both value and format keys.
   *
   * @covers ::onDelta
   */
  public function testFormattedTextFieldEmitsFullObjectInDeltas(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyEmitter(),
      ['body' => TRUE],
    );

    // First chunk: body with formatted text object.
    $streamer->onDelta(['fields' => ['body' => ['value' => 'First', 'format' => 'full_html']]]);
    // Second chunk: updated body value.
    $streamer->onDelta(['fields' => ['body' => ['value' => 'First paragraph.', 'format' => 'full_html']]]);

    $events = $this->capturedEvents();

    // Initial snapshot + 2 deltas.
    $this->assertCount(3, $events);

    // First delta: full body object (transient).
    $this->assertSame('data-drafted-fields', $events[1]['type']);
    $this->assertTrue($events[1]['transient']);
    $this->assertSame(
      ['value' => 'First', 'format' => 'full_html'],
      $events[1]['data']['body'],
    );

    // Second delta: updated body value in full object (transient).
    $this->assertSame('data-drafted-fields', $events[2]['type']);
    $this->assertTrue($events[2]['transient']);
    $this->assertSame(
      ['value' => 'First paragraph.', 'format' => 'full_html'],
      $events[2]['data']['body'],
    );
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
      $this->createSpyEmitter(),
      ['title' => TRUE],
    );

    // "titl" is not in the field index.
    $streamer->onDelta(['fields' => ['titl' => 'Hello']]);

    $events = $this->capturedEvents();

    // Only the initial snapshot, no deltas.
    $this->assertCount(1, $events);
    $this->assertSame('data-drafted-fields', $events[0]['type']);
    $this->assertTrue($events[0]['transient']);
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
      $this->createSpyEmitter(),
      ['title' => TRUE],
    );

    $streamer->onDelta(['fields' => ['title' => 'Same']]);
    $streamer->onDelta(['fields' => ['title' => 'Same']]);

    $events = $this->capturedEvents();

    // Initial snapshot + 1 delta only (second call is a no-op).
    $this->assertCount(2, $events);
  }

  /**
   * Tests that multiple fields appear progressively.
   *
   * When a second field appears in the stream, the delta payload
   * should contain the complete current field state (both fields).
   * The event is emitted only because the state changed.
   *
   * @covers ::onDelta
   */
  public function testMultipleFieldsAppearProgressively(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyEmitter(),
      ['title' => TRUE, 'body' => TRUE],
    );

    // First chunk: only title.
    $streamer->onDelta(['fields' => ['title' => 'Hello']]);
    // Second chunk: title unchanged, body appears.
    $streamer->onDelta(['fields' => ['title' => 'Hello', 'body' => ['value' => 'Text', 'format' => 'html']]]);

    $events = $this->capturedEvents();

    // Initial snapshot + 2 deltas.
    $this->assertCount(3, $events);

    // Second delta: full state contains both title and body (transient).
    $this->assertSame('data-drafted-fields', $events[2]['type']);
    $this->assertTrue($events[2]['transient']);
    $this->assertArrayHasKey('title', $events[2]['data']);
    $this->assertArrayHasKey('body', $events[2]['data']);
    $this->assertSame('Hello', $events[2]['data']['title']);
    $this->assertSame(
      ['value' => 'Text', 'format' => 'html'],
      $events[2]['data']['body'],
    );
  }

  /**
   * Tests that emitFinalSnapshot sends a reconciliation event.
   *
   * The final event should contain the complete drafted fields state
   * in the data key and must NOT have a transient flag, so that it
   * is persisted in the message history on the frontend.
   *
   * @covers ::emitFinalSnapshot
   */
  public function testEmitFinalSnapshotSendsCompleteState(): void {
    $streamer = new ToolCallFieldStreamer(
      $this->createSpyEmitter(),
      ['title' => TRUE],
    );

    $fields = ['title' => 'Final title'];
    $streamer->emitFinalSnapshot($fields);

    $events = $this->capturedEvents();
    $this->assertCount(1, $events);
    $this->assertSame('data-drafted-fields', $events[0]['type']);

    // Final snapshot must NOT carry the transient flag.
    $this->assertArrayNotHasKey('transient', $events[0]);

    $this->assertSame(
      ['title' => 'Final title'],
      $events[0]['data'],
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
      $this->createSpyEmitter(),
      ['title' => TRUE],
    );

    // No "fields" wrapper: bare field map.
    $streamer->onDelta(['title' => 'Direct']);

    $events = $this->capturedEvents();

    // Initial snapshot + 1 delta.
    $this->assertCount(2, $events);

    $this->assertSame('data-drafted-fields', $events[1]['type']);
    $this->assertTrue($events[1]['transient']);
    $this->assertSame('Direct', $events[1]['data']['title']);
  }

}
