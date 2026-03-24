<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting;

use Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter;
use Swis\AgUiServer\Events\StateDeltaEvent;
use Swis\AgUiServer\Events\StateSnapshotEvent;
use function Cortex\JsonRepair\json_repair_decode;

/**
 * Streams tool call argument deltas as incremental field updates.
 *
 * Receives partial JSON strings from the LLM's streaming tool call
 * arguments, repairs the incomplete JSON using cortexphp/json-repair,
 * diffs against the previous state, and emits STATE_DELTA events for
 * each changed field.
 *
 * Lifecycle:
 * 1. emitInitialSnapshot() -- empty draftedFields snapshot (idempotent).
 * 2. onDelta() -- called per streaming chunk; repairs JSON, diffs, emits.
 * 3. emitFinalSnapshot() -- reconciliation snapshot with complete fields.
 *
 * The initial snapshot is emitted lazily on the first onDelta() call
 * if it has not been sent explicitly.
 */
class ToolCallFieldStreamer {

  /**
   * Whether the initial snapshot has been sent.
   *
   * Guards emitInitialSnapshot() to ensure it fires only once.
   *
   * @var bool
   */
  private bool $initialized = FALSE;

  /**
   * The previous repaired state used for diffing.
   *
   * Keyed by field machine name. Updated after each successful diff
   * so that only changed fields produce delta events.
   *
   * @var array
   */
  private array $previousState = [];

  /**
   * Constructs a ToolCallFieldStreamer.
   *
   * @param \Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter $transporter
   *   The SSE transporter used to emit STATE_SNAPSHOT and STATE_DELTA
   *   events to the browser.
   * @param array $fieldIndex
   *   Schema field definitions keyed by machine name. Used to filter
   *   out phantom keys produced by json_repair_decode that do not
   *   correspond to actual content type fields.
   */
  public function __construct(
    private readonly DrupalSseTransporter $transporter,
    private readonly array $fieldIndex,
  ) {}

  /**
   * Emits the initial empty-state snapshot (idempotent).
   *
   * Sends a StateSnapshotEvent with an empty draftedFields object.
   * Subsequent calls are no-ops, guarded by the $initialized flag.
   * This is called lazily by onDelta() on the first chunk, but can
   * also be called explicitly before streaming begins.
   */
  public function emitInitialSnapshot(): void {
    if ($this->initialized) {
      return;
    }
    $this->initialized = TRUE;
    $this->transporter->sendEvent(
      new StateSnapshotEvent(['draftedFields' => []]),
    );
  }

  /**
   * Processes a partial tool call argument JSON chunk.
   *
   * Repairs the incomplete JSON, extracts fields, filters against the
   * field index, diffs against the previous state, and emits a
   * StateDeltaEvent with JSON Patch operations for any changed fields.
   *
   * @param string $partialArgumentsJson
   *   The partial JSON string from the LLM's streaming tool call
   *   arguments. May be incomplete (e.g. '{"title": "EU L').
   */
  public function onDelta(string $partialArgumentsJson): void {
    // Ensure the initial snapshot has been sent.
    $this->emitInitialSnapshot();

    // Attempt to repair and decode the partial JSON.
    $repaired = $this->repairJson($partialArgumentsJson);
    if ($repaired === NULL) {
      return;
    }

    // Extract fields, handling both wrapped and unwrapped formats.
    // The LLM may emit {"fields": {"title": "..."}} or just
    // {"title": "..."} depending on the prompt.
    $fields = $repaired['fields'] ?? $repaired;

    // Ensure we have an array of fields.
    if (!is_array($fields)) {
      return;
    }

    // Filter out phantom keys not in the field index.
    $fields = array_intersect_key($fields, $this->fieldIndex);

    // Diff against previous state to find changes.
    $ops = $this->diff($this->previousState, $fields);
    if (empty($ops)) {
      return;
    }

    // Emit the delta event with JSON Patch operations.
    $this->transporter->sendEvent(new StateDeltaEvent($ops));

    // Update the previous state for next diff.
    $this->previousState = $fields;
  }

  /**
   * Emits a final reconciliation snapshot with complete field values.
   *
   * Called after the tool call is complete to ensure the frontend
   * has the authoritative field state.
   *
   * @param array $fields
   *   Complete field values keyed by machine name.
   */
  public function emitFinalSnapshot(array $fields): void {
    $this->transporter->sendEvent(
      new StateSnapshotEvent(['draftedFields' => $fields]),
    );
  }

  /**
   * Repairs partial JSON and returns an associative array.
   *
   * Uses cortexphp/json-repair to handle incomplete JSON strings
   * from streaming tool call arguments. Returns NULL if the input
   * cannot be repaired into a usable structure.
   *
   * @param string $json
   *   The partial JSON string to repair.
   *
   * @return array|null
   *   The decoded associative array, or NULL if repair failed.
   */
  private function repairJson(string $json): ?array {
    if ($json === '') {
      return NULL;
    }

    try {
      $result = json_repair_decode($json);
    }
    catch (\Throwable) {
      return NULL;
    }

    // Convert stdClass to array recursively.
    if ($result instanceof \stdClass) {
      $result = (array) $result;
    }

    // Only accept arrays (objects decoded as arrays).
    if (!is_array($result)) {
      return NULL;
    }

    // Recursively convert any nested stdClass objects.
    array_walk_recursive($result, function (&$value): void {
      if ($value instanceof \stdClass) {
        $value = (array) $value;
      }
    });

    // Re-cast top-level values that are stdClass after walk.
    foreach ($result as $key => $value) {
      if ($value instanceof \stdClass) {
        $result[$key] = (array) $value;
      }
    }

    return $result;
  }

  /**
   * Computes JSON Patch operations between two field states.
   *
   * Compares old and new field maps to produce "add" and "replace"
   * operations. Formatted text fields (arrays with a "value" key)
   * target the /value sub-path.
   *
   * Field names are escaped per RFC 6901 (JSON Pointer):
   * ~ becomes ~0, / becomes ~1.
   *
   * @param array $old
   *   The previous field state.
   * @param array $new
   *   The current field state.
   *
   * @return array
   *   An array of JSON Patch operation arrays, each with op, path,
   *   and value keys.
   */
  private function diff(array $old, array $new): array {
    $ops = [];

    foreach ($new as $name => $value) {
      // Escape field name for JSON Pointer (RFC 6901).
      $escaped = str_replace(['~', '/'], ['~0', '~1'], $name);

      if (!array_key_exists($name, $old)) {
        // New field: always add the whole value so the parent
        // path exists in state. For formatted text fields, this
        // creates the object with value/format/summary keys.
        // Subsequent replace ops can then target /value directly.
        $ops[] = [
          'op' => 'add',
          'path' => '/draftedFields/' . $escaped,
          'value' => $value,
        ];
      }
      elseif ($old[$name] !== $value) {
        // Changed field: emit "replace" operation.
        if (is_array($value) && array_key_exists('value', $value)) {
          // Formatted text: target the /value sub-key.
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
