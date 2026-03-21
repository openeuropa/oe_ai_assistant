<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting;

use Drupal\oe_ai_assistant\Transporter\DrupalSseTransporter;
use Swis\AgUiServer\Events\StateSnapshotEvent;

/**
 * Streams drafted fields to the frontend via STATE_SNAPSHOT SSE events.
 *
 * Handles two streaming modes:
 * - Word-by-word (progressive): long text fields that qualify for streaming.
 * - Whole-field (single snapshot): short or non-text fields.
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
   *   The SSE transporter used to emit STATE_SNAPSHOT events to the browser.
   *   The transporter handles the low-level SSE framing (data:, event:, id:
   *   lines) and flushes the output buffer after each event.
   */
  public function __construct(
    private readonly DrupalSseTransporter $transporter,
  ) {}

  /**
   * Streams drafted fields as STATE_SNAPSHOT SSE events.
   *
   * Entry point called by DraftingPlugin's tool executor closure after the
   * LLM invokes draft_content. The method determines which fields to include
   * in the SSE stream, then iterates over them emitting either progressive
   * word-by-word events or a single whole-field event per field.
   *
   * Each STATE_SNAPSHOT event wraps a "draftedFields" key containing all
   * fields streamed so far (including fields from previous iterations of the
   * loop). The frontend applies each snapshot as a merge, so a field already
   * present in the frontend state is overwritten only when it appears in the
   * snapshot.
   *
   * Selective streaming (regeneration): when $fieldsToStream is non-empty,
   * only those fields are included in the output. Omitted fields remain
   * unchanged in the frontend state because they simply never appear in any
   * snapshot during this call.
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
    // Determine the set of fields to include in the output.
    // On regeneration with specific fields requested ($fieldsToStream
    // non-empty), restrict to only those fields so the rest of the draft
    // stays untouched in the frontend. On first draft or when no specific
    // fields are requested, pass through all drafted fields.
    $targetFields = $fields;
    if (!empty($fieldsToStream)) {
      // array_flip converts ["title", "body"] into ["title" => 0, "body" => 1]
      // so array_intersect_key can use it as a key mask.
      $targetFields = array_intersect_key(
        $fields,
        array_flip($fieldsToStream),
      );
    }

    // Decide the progressive-streaming mode. Three cases apply:
    // NULL means first draft, so all long text fields are progressive.
    // A non-empty associative array means regeneration with specific fields;
    // only those fields are progressive (keyed by name for O(1) lookup).
    // An empty array means regeneration without a field hint, so no
    // progressive streaming and all fields arrive as single snapshots.
    $progressiveFields = [];
    if ($isFirstDraft) {
      // NULL signals "all eligible fields are progressive" to the loop below.
      $progressiveFields = NULL;
    }
    elseif (!empty($fieldsToStream)) {
      // Convert the list to a hash map for O(1) isset() checks in the loop.
      $progressiveFields = array_flip($fieldsToStream);
    }

    // Accumulator: holds all fields emitted so far in this call.
    // Each STATE_SNAPSHOT event carries the full accumulator so the frontend
    // always receives a complete partial state, not just a delta.
    $streamed = [];

    foreach ($targetFields as $name => $value) {
      // A field is streamed word-by-word only when both conditions hold:
      // (1) its widget type is a textarea or formatted text editor, and
      // (2) it is in the progressive set, or progressive mode is unrestricted
      // on first draft (signalled by $progressiveFields === NULL).
      $shouldStream = $this->isStreamableField($name, $value, $fieldIndex)
        && ($progressiveFields === NULL || isset($progressiveFields[$name]));

      if ($shouldStream && is_string($value) && mb_strlen($value) > 50) {
        // Plain string field (e.g. "title" or "field_summary"): split on
        // whitespace while PREG_SPLIT_DELIM_CAPTURE retains whitespace tokens
        // so the reconstructed partial string has correct spacing.
        $words = preg_split('/(\s+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $partial = '';
        foreach ($words as $word) {
          // Append each token (word or whitespace run) to the growing partial
          // value and emit a snapshot after every token so the frontend
          // updates smoothly.
          $partial .= $word;
          $streamed[$name] = $partial;
          $this->transporter->sendEvent(new StateSnapshotEvent(['draftedFields' => $streamed]));
        }
      }
      elseif ($shouldStream && is_array($value) && isset($value['value'])
        && is_string($value['value']) && mb_strlen($value['value']) > 50) {
        // Formatted text field (e.g. "body"): the LLM returns an array with
        // at least a "value" key (the HTML string) and optionally "format"
        // and "summary" keys. We stream only the "value" key word-by-word
        // and preserve the rest of the array on every snapshot so the
        // frontend always has a complete, valid field structure.
        $words = preg_split('/(\s+)/', $value['value'], -1, PREG_SPLIT_DELIM_CAPTURE);
        $partial = '';
        foreach ($words as $word) {
          $partial .= $word;
          // array_merge preserves extra keys (format, summary, etc.) while
          // replacing only the "value" key with the growing partial HTML.
          $streamed[$name] = array_merge($value, ['value' => $partial]);
          $this->transporter->sendEvent(new StateSnapshotEvent(['draftedFields' => $streamed]));
        }
      }
      else {
        // Short text, number, date, entity reference stub, or any field
        // not eligible for progressive streaming: emit a single snapshot
        // with the complete value.
        $streamed[$name] = $value;
        $this->transporter->sendEvent(new StateSnapshotEvent(['draftedFields' => $streamed]));
      }
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
