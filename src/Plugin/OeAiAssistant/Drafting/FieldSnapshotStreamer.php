<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\OeAiAssistant\Drafting;

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
   *   The SSE transporter used to emit STATE_SNAPSHOT events.
   */
  public function __construct(
    private readonly DrupalSseTransporter $transporter,
  ) {}

  /**
   * Streams drafted fields as STATE_SNAPSHOT SSE events.
   *
   * On regeneration with specific fields requested, only those fields are
   * sent. The frontend merges partial snapshots with its existing state,
   * so omitted fields stay untouched.
   *
   * @param array $fields
   *   Drafted fields keyed by machine name.
   * @param array $fieldIndex
   *   Schema field definitions keyed by machine name.
   * @param array $fieldsToStream
   *   Field machine names explicitly requested for streaming. Empty means
   *   either all fields (first draft) or no progressive streaming
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
    // On regeneration with specific fields requested, only send those.
    // The frontend merges partial snapshots with its existing state,
    // so omitted fields stay untouched.
    $targetFields = $fields;
    if (!empty($fieldsToStream)) {
      $targetFields = array_intersect_key(
        $fields,
        array_flip($fieldsToStream),
      );
    }

    // Determine which fields should be streamed word-by-word:
    // - First draft: all long text fields (progressive reveal).
    // - Regeneration with [fields:] tag: only the requested fields.
    // - Regeneration without [fields:] tag: none (send all at once).
    $progressiveFields = [];
    if ($isFirstDraft) {
      // Stream all long text fields on first draft.
      $progressiveFields = NULL;
    }
    elseif (!empty($fieldsToStream)) {
      // Stream only explicitly requested fields on regeneration.
      $progressiveFields = array_flip($fieldsToStream);
    }

    $streamed = [];

    foreach ($targetFields as $name => $value) {
      // Only word-by-word stream fields that qualify: must be a long
      // text field AND must be in the progressive set (or all fields
      // are progressive on first draft when $progressiveFields is NULL).
      $shouldStream = $this->isStreamableField($name, $value, $fieldIndex)
        && ($progressiveFields === NULL || isset($progressiveFields[$name]));

      if ($shouldStream && is_string($value) && mb_strlen($value) > 50) {
        $words = preg_split('/(\s+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $partial = '';
        foreach ($words as $word) {
          $partial .= $word;
          $streamed[$name] = $partial;
          $this->transporter->sendEvent(new StateSnapshotEvent(['draftedFields' => $streamed]));
        }
      }
      elseif ($shouldStream && is_array($value) && isset($value['value'])
        && is_string($value['value']) && mb_strlen($value['value']) > 50) {
        $words = preg_split('/(\s+)/', $value['value'], -1, PREG_SPLIT_DELIM_CAPTURE);
        $partial = '';
        foreach ($words as $word) {
          $partial .= $word;
          $streamed[$name] = array_merge($value, ['value' => $partial]);
          $this->transporter->sendEvent(new StateSnapshotEvent(['draftedFields' => $streamed]));
        }
      }
      else {
        $streamed[$name] = $value;
        $this->transporter->sendEvent(new StateSnapshotEvent(['draftedFields' => $streamed]));
      }
    }
  }

  /**
   * Determines whether a field should be streamed word-by-word.
   *
   * Uses the widget type from the content type schema: textarea and
   * formatted text widgets are streamable; all others arrive whole.
   *
   * @param string $name
   *   The field machine name.
   * @param mixed $value
   *   The field value from the LLM.
   * @param array $fieldIndex
   *   Schema field definitions keyed by machine name.
   *
   * @return bool
   *   TRUE if the field should be streamed progressively.
   */
  private function isStreamableField(string $name, mixed $value, array $fieldIndex): bool {
    $widget = $fieldIndex[$name]['widget'] ?? '';
    return in_array($widget, [
      'text',
      'textarea',
      'textarea_formatted',
      'textarea_formatted_summary',
    ], TRUE);
  }

}
