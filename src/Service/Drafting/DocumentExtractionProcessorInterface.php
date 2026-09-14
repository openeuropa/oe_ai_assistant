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
   * Processes a document as far as it can go and returns its final state.
   *
   * Claims the document atomically, then runs the steps it still needs:
   * extraction when no extract is stored, summarisation afterwards. Each
   * step persists before the next starts. Failures land the document in
   * the error state and are logged; nothing is thrown.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media.
   * @param bool $reclaimInFlight
   *   TRUE to take over a document stuck in an in-flight state (cron only).
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
   * The cron safety net for documents the app never triggered, lost
   * requests and crashed runs.
   *
   * @param int $limit
   *   Maximum number of documents.
   * @param int $staleAfterSeconds
   *   Age after which an in-flight document counts as abandoned.
   */
  public function processPending(int $limit, int $staleAfterSeconds): void;

}
