<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting;

use Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter;
use Swis\AgUiServer\Events\StateDeltaEvent;
use Swis\AgUiServer\Events\StateSnapshotEvent;

use function Cortex\JsonRepair\json_repair_decode;

/**
 * Streams tool call field arguments as AG-UI state events.
 *
 * Receives incremental JSON fragments from a streaming LLM tool call,
 * repairs them with cortexphp/json-repair, diffs against the previous
 * state, and emits AG-UI StateDelta / StateSnapshot events via the
 * SSE transporter.
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
   * @param \Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter $transporter
   *   The SSE transporter for sending AG-UI events.
   * @param array<string, mixed> $fieldIndex
   *   An associative array of known field names. Only fields whose
   *   keys appear in this index will be included in state events.
   */
  public function __construct(
    private readonly DrupalSseTransporter $transporter,
    private readonly array $fieldIndex,
  ) {}

  /**
   * Emits the initial empty-state snapshot (idempotent).
   *
   * Sends a StateSnapshotEvent with an empty draftedFields array.
   * Subsequent calls are no-ops.
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
   * Processes an incremental JSON fragment from the tool call stream.
   *
   * Repairs the partial JSON, extracts and filters fields against the
   * field index, diffs against the previous state, and emits a
   * StateDeltaEvent if any fields changed.
   *
   * @param string $partialArgumentsJson
   *   The raw (possibly incomplete) JSON string from the LLM stream.
   */
  public function onDelta(string $partialArgumentsJson): void {
    // Ensure the initial snapshot is emitted before any deltas.
    $this->emitInitialSnapshot();

    // Attempt to repair and decode the partial JSON.
    $repaired = $this->repairJson($partialArgumentsJson);
    if ($repaired === NULL) {
      return;
    }

    // Normalize: support both {"fields": {...}} and bare {...} formats.
    $fields = $repaired['fields'] ?? $repaired;

    // Filter out any fields not present in the known field index.
    $fields = array_intersect_key($fields, $this->fieldIndex);

    // Compute the diff between previous and current state.
    $ops = $this->diff($this->previousState, $fields);

    // Emit a delta event only when there are actual changes.
    if (!empty($ops)) {
      $this->transporter->sendEvent(new StateDeltaEvent($ops));
      $this->previousState = $fields;
    }
  }

  /**
   * Emits a final reconciliation snapshot with the complete field state.
   *
   * @param array<string, mixed> $fields
   *   The final drafted fields to include in the snapshot.
   */
  public function emitFinalSnapshot(array $fields): void {
    $this->transporter->sendEvent(
      new StateSnapshotEvent(['draftedFields' => $fields]),
    );
  }

  /**
   * Repairs and decodes a possibly malformed JSON string.
   *
   * Uses cortexphp/json-repair to fix common LLM JSON issues such as
   * trailing commas, missing brackets, and incomplete strings.
   *
   * @param string $json
   *   The raw JSON string to repair.
   *
   * @return array<string, mixed>|null
   *   The decoded associative array, or NULL if the input is empty
   *   or cannot be repaired into a valid array.
   */
  private function repairJson(string $json): ?array {
    if ($json === '') {
      return NULL;
    }

    try {
      $decoded = json_repair_decode($json);
    }
    catch (\Throwable) {
      return NULL;
    }

    // The library may return stdClass; convert to array.
    if ($decoded instanceof \stdClass) {
      $decoded = (array) $decoded;
    }

    // Only associative arrays are valid field maps.
    if (!is_array($decoded)) {
      return NULL;
    }

    return $decoded;
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
        // New field: add the whole value (including formatted text
        // objects) so the parent path exists for future patches.
        $ops[] = [
          'op' => 'add',
          'path' => '/draftedFields/' . $escaped,
          'value' => $value,
        ];
      }
      elseif ($old[$name] !== $value) {
        // Changed field: for formatted text (array with "value" key
        // where old is also an array), replace only the value subkey.
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
