<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting;

use Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter;
use Swis\AgUiServer\Events\StateDeltaEvent;
use Swis\AgUiServer\Events\StateSnapshotEvent;

/**
 * Streams drafted fields to the frontend via SSE events.
 *
 * Uses a three-phase event lifecycle:
 * 1. Skeleton STATE_SNAPSHOT: all fields present, progressive fields empty.
 * 2. Per-word STATE_DELTA: JSON Patch replace operations for progressive
 *    fields, one per whitespace-delimited token.
 * 3. Final STATE_SNAPSHOT: complete state for reconciliation.
 *
 * Non-progressive fields (short text, numbers, dates, entity references)
 * carry their final values in both the skeleton and final snapshots.
 *
 * On first draft all long text fields are streamed progressively. On
 * regeneration only the explicitly requested fields are streamed; the
 * rest are sent in one snapshot so the frontend can merge them.
 */
class FieldSnapshotStreamer {

  /**
   * Constructs a FieldSnapshotStreamer.
   *
   * @param \Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter $transporter
   *   The SSE transporter used to emit STATE_SNAPSHOT and STATE_DELTA
   *   events to the browser. The transporter handles the low-level SSE
   *   framing (data:, event:, id: lines) and flushes the output buffer
   *   after each event.
   */
  public function __construct(
    private readonly DrupalSseTransporter $transporter,
  ) {}

  /**
   * Streams drafted fields via the three-phase event lifecycle.
   *
   * Entry point called by DraftingPlugin's tool executor closure after the
   * LLM invokes draft_content. The method determines which fields to include
   * and which qualify for progressive streaming, then emits:
   *
   * 1. A skeleton STATE_SNAPSHOT with all target fields present
   *    (progressive fields set to empty placeholders).
   * 2. One STATE_DELTA per whitespace token for each progressive field,
   *    carrying a JSON Patch "replace" operation.
   * 3. A final STATE_SNAPSHOT with the complete state for reconciliation.
   *
   * Selective streaming (regeneration): when $fieldsToStream is non-empty,
   * only those fields are included in the output. Omitted fields remain
   * unchanged in the frontend state because they do not appear in any
   * event during this call.
   *
   * @param array $fields
   *   Drafted fields keyed by machine name, as returned by the LLM tool call.
   *   Values may be plain strings, associative arrays with a "value" key
   *   (formatted text fields), or any other JSON-serialisable shape.
   * @param array $fieldIndex
   *   Schema field definitions keyed by machine name, built by
   *   DraftingPromptBuilder::buildFieldIndex(). Each entry must contain at
   *   least a "widget" key describing the Drupal form widget type.
   * @param array $fieldsToStream
   *   Field machine names explicitly requested for streaming. An empty array
   *   means either all fields (first draft) or no progressive streaming
   *   (regeneration without a [fields:] tag).
   * @param bool $isFirstDraft
   *   TRUE if this is the initial draft (stream all long text fields
   *   progressively), FALSE if this is a regeneration request.
   */
  public function stream(
    array $fields,
    array $fieldIndex,
    array $fieldsToStream,
    bool $isFirstDraft,
  ): void {
    $targetFields = $this->resolveTargetFields($fields, $fieldsToStream);
    $progressiveFields = $this->resolveProgressiveMode(
      $isFirstDraft,
      $fieldsToStream,
    );

    // Phase 1: skeleton snapshot with placeholders for progressive fields.
    [$streamed, $progressiveFieldNames, $escapedNames] = $this->buildSkeleton(
      $targetFields,
      $fieldIndex,
      $progressiveFields,
    );
    $this->transporter->sendEvent(
      new StateSnapshotEvent(['draftedFields' => $streamed]),
    );

    // Phase 2: word-by-word deltas for progressive fields.
    $this->streamDeltas(
      $targetFields,
      $progressiveFieldNames,
      $escapedNames,
      $streamed,
    );

    // Phase 3: final snapshot with complete state for reconciliation.
    $this->transporter->sendEvent(
      new StateSnapshotEvent(['draftedFields' => $streamed]),
    );
  }

  /**
   * Filters fields to only those requested for streaming.
   *
   * On regeneration with specific fields requested, restricts to only
   * those fields so the rest of the draft stays untouched in the
   * frontend. On first draft or when no specific fields are requested,
   * passes through all drafted fields.
   *
   * @param array $fields
   *   All drafted fields keyed by machine name.
   * @param array $fieldsToStream
   *   Field names explicitly requested, or empty for all.
   *
   * @return array
   *   The subset of fields to include in the output.
   */
  private function resolveTargetFields(array $fields, array $fieldsToStream): array {
    if (empty($fieldsToStream)) {
      return $fields;
    }
    // array_flip converts ["title", "body"] into ["title" => 0, "body" => 1]
    // so array_intersect_key can use it as a key mask.
    return array_intersect_key($fields, array_flip($fieldsToStream));
  }

  /**
   * Determines the progressive-streaming mode.
   *
   * Three cases:
   * - NULL: first draft, all eligible fields are progressive.
   * - Non-empty array: regeneration with specific fields as progressive
   *   (keyed by name for O(1) lookup).
   * - Empty array: regeneration without a field hint, no progressive
   *   streaming.
   *
   * @param bool $isFirstDraft
   *   TRUE if this is the initial draft.
   * @param array $fieldsToStream
   *   Field names explicitly requested for streaming.
   *
   * @return array|null
   *   NULL for unrestricted progressive mode, or a hash map of
   *   progressive field names, or an empty array for none.
   */
  private function resolveProgressiveMode(bool $isFirstDraft, array $fieldsToStream): ?array {
    if ($isFirstDraft) {
      return NULL;
    }
    if (!empty($fieldsToStream)) {
      return array_flip($fieldsToStream);
    }
    return [];
  }

  /**
   * Builds the skeleton state for phase 1.
   *
   * Iterates all target fields and classifies each as progressive or
   * non-progressive. Non-progressive fields carry their final values.
   * Progressive fields carry empty placeholders (empty string for plain
   * text, or the field array with an empty "value" key for formatted text).
   *
   * Also prepares JSON Pointer-escaped field names (RFC 6901: ~ becomes
   * ~0, / becomes ~1). Drupal field names are [a-z0-9_] only so this
   * is defensive.
   *
   * @param array $targetFields
   *   Fields to include in the skeleton, keyed by machine name.
   * @param array $fieldIndex
   *   Schema field definitions keyed by machine name.
   * @param array|null $progressiveFields
   *   NULL for unrestricted, hash map of progressive names, or empty.
   *
   * @return array
   *   A three-element array:
   *   - $streamed: the skeleton state keyed by field name.
   *   - $progressiveFieldNames: ordered list of progressive field names.
   *   - $escapedNames: JSON Pointer-escaped names keyed by field name.
   */
  private function buildSkeleton(
    array $targetFields,
    array $fieldIndex,
    ?array $progressiveFields,
  ): array {
    $streamed = [];
    $progressiveFieldNames = [];
    $escapedNames = [];

    foreach ($targetFields as $name => $value) {
      // Escape field name for JSON Pointer (RFC 6901).
      $escapedNames[$name] = str_replace(
        ['~', '/'],
        ['~0', '~1'],
        $name,
      );

      $isProgressive = $this->isStreamableField($name, $value, $fieldIndex)
        && ($progressiveFields === NULL || isset($progressiveFields[$name]));

      if ($isProgressive && is_string($value) && mb_strlen($value) > 50) {
        // Plain string placeholder: empty string.
        $streamed[$name] = '';
        $progressiveFieldNames[] = $name;
      }
      elseif ($isProgressive && is_array($value) && isset($value['value'])
        && is_string($value['value']) && mb_strlen($value['value']) > 50) {
        // Formatted text placeholder: preserve structure,
        // empty the value key.
        $streamed[$name] = array_merge($value, ['value' => '']);
        $progressiveFieldNames[] = $name;
      }
      else {
        // Non-progressive: final value immediately.
        $streamed[$name] = $value;
      }
    }

    return [$streamed, $progressiveFieldNames, $escapedNames];
  }

  /**
   * Streams progressive fields word-by-word as JSON Patch deltas.
   *
   * For each progressive field, splits the text on whitespace and emits
   * one StateDeltaEvent per token with a "replace" operation targeting
   * the specific field path. Also updates the $streamed accumulator
   * with final values for use in the phase 3 snapshot.
   *
   * @param array $targetFields
   *   All target fields with their final values.
   * @param array $progressiveFieldNames
   *   Ordered list of field names to stream progressively.
   * @param array $escapedNames
   *   JSON Pointer-escaped names keyed by field name.
   * @param array &$streamed
   *   The skeleton state, updated in place with final values after
   *   each field finishes streaming.
   */
  private function streamDeltas(
    array $targetFields,
    array $progressiveFieldNames,
    array $escapedNames,
    array &$streamed,
  ): void {
    foreach ($progressiveFieldNames as $name) {
      $value = $targetFields[$name];

      if (is_string($value)) {
        // Plain string field: patch the field value directly.
        $this->streamWords(
          $value,
          '/draftedFields/' . $escapedNames[$name],
        );
        $streamed[$name] = $value;
      }
      elseif (is_array($value) && isset($value['value'])) {
        // Formatted text field: patch only the "value" key,
        // leaving format/summary intact from the skeleton.
        $this->streamWords(
          $value['value'],
          '/draftedFields/' . $escapedNames[$name] . '/value',
        );
        $streamed[$name] = $value;
      }
    }
  }

  /**
   * Splits text on whitespace and emits one delta per token.
   *
   * Uses PREG_SPLIT_DELIM_CAPTURE to preserve whitespace tokens so the
   * reconstructed partial string has correct spacing.
   *
   * @param string $text
   *   The full text to stream word-by-word.
   * @param string $path
   *   The JSON Pointer path for the replace operation.
   */
  private function streamWords(string $text, string $path): void {
    $words = preg_split(
      '/(\s+)/',
      $text,
      -1,
      PREG_SPLIT_DELIM_CAPTURE,
    );
    $partial = '';
    foreach ($words as $word) {
      $partial .= $word;
      $this->transporter->sendEvent(new StateDeltaEvent([
        [
          'op' => 'replace',
          'path' => $path,
          'value' => $partial,
        ],
      ]));
    }
  }

  /**
   * Determines whether a field should be streamed word-by-word.
   *
   * Uses the widget type from the content type schema to classify the field.
   * Only textarea and formatted text widgets produce long prose that benefits
   * from word-by-word streaming. Short input, date, number, and reference
   * widgets are always sent as a single snapshot regardless of value length.
   *
   * The widget type is sourced from the field index built by
   * DraftingPromptBuilder::buildFieldIndex(), which in turn comes from
   * FormSchemaExtractor. If the field is not in the index (e.g. the bundle
   * was unknown at prompt build time), the widget defaults to an empty string
   * and the field is treated as non-streamable.
   *
   * @param string $name
   *   The field machine name (e.g. "body", "field_summary").
   * @param mixed $value
   *   The field value from the LLM (not used here, reserved for future use
   *   where value shape might inform the streaming decision).
   * @param array $fieldIndex
   *   Schema field definitions keyed by machine name. Each entry is expected
   *   to have at least a "widget" string key.
   *
   * @return bool
   *   TRUE if the field should be streamed progressively, FALSE otherwise.
   */
  private function isStreamableField(string $name, mixed $value, array $fieldIndex): bool {
    // Look up the widget type for this field. An unknown field (not in the
    // index) gets an empty string and falls through to the FALSE return.
    $widget = $fieldIndex[$name]['widget'] ?? '';

    // The four widget types below produce long text that benefits from
    // progressive streaming. All other widget types (textfield, number,
    // date, select, entity_autocomplete, etc.) return FALSE.
    return in_array($widget, [
      'text',
      'textarea',
      'textarea_formatted',
      'textarea_formatted_summary',
    ], TRUE);
  }

}
