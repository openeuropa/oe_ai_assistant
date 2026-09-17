<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\media\MediaInterface;

/**
 * Drives session documents through extraction and summarisation.
 *
 * The state_machine module owns the workflow, its transitions and their
 * validation; the processor owns the state ids, the field they live in and
 * the order of the steps.
 */
interface DocumentExtractionProcessorInterface {

  /**
   * The media field holding the state.
   */
  public const string STATE_FIELD = 'oe_ai_extraction_state';

  /**
   * The media field holding the full extracted text.
   */
  public const string EXTRACT_FIELD = 'oe_ai_document_extract';

  /**
   * The media field holding the brief summary.
   */
  public const string SUMMARY_FIELD = 'oe_ai_document_summary';

  /**
   * Resting state of a new or reset document.
   */
  public const string STATE_SCHEDULED = 'scheduled';

  /**
   * In-flight state of the text extraction step.
   */
  public const string STATE_EXTRACTING = 'extracting';

  /**
   * Resting state after the extract was stored.
   */
  public const string STATE_EXTRACTED = 'extracted';

  /**
   * In-flight state of the summary step.
   */
  public const string STATE_SUMMARIZING = 'summarizing';

  /**
   * Final state with extract and summary stored.
   */
  public const string STATE_DONE = 'done';

  /**
   * Final state after a failed step.
   */
  public const string STATE_ERROR = 'error';

  /**
   * Resets a document so the pipeline runs again from the start.
   *
   * Clears the stored extract and summary and puts the document in the
   * scheduled state. Nothing is saved: the caller owns the save, typically
   * a presave hook on a new document or one whose file was replaced.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media entity.
   */
  public function schedule(MediaInterface $media): void;

  /**
   * Processes a document as far as it can go and returns its final state.
   *
   * Claims the document atomically, then runs the steps it still needs:
   * extraction when no extract is stored, summarisation afterwards. Each
   * step persists before the next starts. Failures land the document in
   * the error state and are logged; nothing is thrown. A document deleted
   * while a step runs is left alone, and the state it had reached is
   * returned.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media.
   * @param bool $reclaimInFlight
   *   TRUE to take over a document stuck in an in-flight state, for callers
   *   that sweep abandoned runs.
   *
   * @return string
   *   The state after the call: unchanged when nothing was claimed.
   */
  public function process(MediaInterface $media, bool $reclaimInFlight = FALSE): string;

  /**
   * Processes the documents that need a run, oldest first.
   *
   * Resting documents in scheduled or extracted come first, then in-flight
   * documents unchanged for longer than the threshold, which are reclaimed.
   * The safety net for documents the app never triggered, lost requests
   * and crashed runs.
   *
   * @param int $limit
   *   Maximum number of documents.
   * @param int $staleAfterSeconds
   *   Age after which an in-flight document counts as abandoned.
   */
  public function processPending(int $limit, int $staleAfterSeconds): void;

}
