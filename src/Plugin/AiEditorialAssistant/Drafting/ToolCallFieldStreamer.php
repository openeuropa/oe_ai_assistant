<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting;

/**
 * Streams drafted field values as Data Stream Protocol events.
 *
 * Receives already-decoded tool call arguments (repaired by the
 * LLM streaming loop), extracts and filters field values, diffs
 * against the previous state, and emits data-drafted-fields events
 * via the provided emitter callable.
 */
class ToolCallFieldStreamer {

  /**
   * Whether the initial empty snapshot has been sent.
   *
   * @var bool
   */
  private bool $initialized = FALSE;

  /**
   * The previous field state, used for diffing.
   *
   * @var array<string, mixed>
   */
  private array $previousState = [];

  /**
   * Constructs a ToolCallFieldStreamer.
   *
   * @param \Closure $emitter
   *   A callable with signature fn(string $type, array $data): void
   *   for emitting SSE events. Typically AiAssistantPluginBase::emitEvent().
   * @param array<string, mixed> $fieldIndex
   *   An associative array of known field names. Only fields whose
   *   keys appear in this index will be included in data events.
   */
  public function __construct(
    private readonly \Closure $emitter,
    private readonly array $fieldIndex,
  ) {}

  /**
   * Emits the initial empty-state snapshot (idempotent).
   *
   * Sends a data-drafted-fields event with an empty object.
   * Subsequent calls are no-ops.
   */
  public function emitInitialSnapshot(): void {
    if ($this->initialized) {
      return;
    }

    $this->initialized = TRUE;
    ($this->emitter)('data-drafted-fields', [
      'data' => (object) [],
      'transient' => TRUE,
    ]);
  }

  /**
   * Processes decoded tool call arguments from the LLM stream.
   *
   * Extracts and filters fields against the field index, diffs
   * against the previous state, and emits a data-drafted-fields event
   * with the full accumulated state if any fields changed. The diff is
   * used only to detect changes; the payload is the complete field map.
   * The JSON repair step is handled upstream by
   * ChatPluginBase::repairPartialJson() before this method is called.
   *
   * @param array $arguments
   *   Already-decoded associative array from the repaired tool call
   *   JSON. May contain a "fields" wrapper key or bare field values.
   */
  public function onDelta(array $arguments): void {
    // Ensure the initial snapshot is emitted before any deltas.
    $this->emitInitialSnapshot();

    // Normalize: support both {"fields": {...}} and bare {...} formats.
    $fields = $arguments['fields'] ?? $arguments;
    if (!is_array($fields)) {
      return;
    }

    // Filter out any fields not present in the known field index.
    $fields = array_intersect_key($fields, $this->fieldIndex);

    // Compute the diff between previous and current state.
    $ops = $this->diff($this->previousState, $fields);

    // Emit a data event only when there are actual changes.
    if (!empty($ops)) {
      ($this->emitter)('data-drafted-fields', [
        'data' => $fields,
        'transient' => TRUE,
      ]);
      $this->previousState = $fields;
    }
  }

  /**
   * Emits a final reconciliation event with the complete field state.
   *
   * Unlike transient delta events, this event has no transient flag
   * so it is persisted in the message history on the frontend.
   *
   * @param array<string, mixed> $fields
   *   The final drafted fields to include in the event.
   */
  public function emitFinalSnapshot(array $fields): void {
    ($this->emitter)('data-drafted-fields', [
      'data' => $fields,
    ]);
  }

  /**
   * Computes JSON Patch operations between two field states.
   *
   * Produces "add" operations for new fields and "replace" operations
   * for changed fields. For formatted text fields (associative arrays
   * with a "value" key), the replace targets the /value subkey to
   * enable incremental UI updates.
   *
   * @param array<string, mixed> $old
   *   The previous field state.
   * @param array<string, mixed> $new
   *   The current field state.
   *
   * @return array<int, array{op: string, path: string, value: mixed}>
   *   A list of JSON Patch operations.
   */
  private function diff(array $old, array $new): array {
    $ops = [];

    foreach ($new as $name => $value) {
      // Escape field name per RFC 6901 JSON Pointer.
      $escaped = str_replace('~', '~0', $name);
      $escaped = str_replace('/', '~1', $escaped);

      if (!array_key_exists($name, $old)) {
        $ops[] = [
          'op' => 'add',
          'path' => '/draftedFields/' . $escaped,
          'value' => $value,
        ];
      }
      elseif ($old[$name] !== $value) {
        if (is_array($value) && isset($value['value']) && is_array($old[$name])) {
          $ops[] = [
            'op' => 'replace',
            'path' => '/draftedFields/' . $escaped . '/value',
            'value' => $value['value'],
          ];
        }
        else {
          $ops[] = [
            'op' => 'replace',
            'path' => '/draftedFields/' . $escaped,
            'value' => $value,
          ];
        }
      }
    }

    return $ops;
  }

}
