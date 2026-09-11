<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\media\MediaInterface;

/**
 * Reads the extraction workflow that tracks session document processing.
 *
 * The workflow is a state_machine workflow stored in a state field. The
 * bundles carrying that field are the working-material bundles.
 */
interface DocumentExtractionWorkflowInterface {

  /**
   * The state_machine workflow id.
   */
  public const string WORKFLOW_ID = 'oe_ai_document_extraction';

  /**
   * The media field holding the state.
   */
  public const string STATE_FIELD = 'oe_ai_extraction_state';

  /**
   * The permission letting a user use any transition in the media form.
   */
  public const string TRANSITION_PERMISSION = 'change ai document extraction state';

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
   * Returns the media bundles carrying the state field.
   *
   * @return string[]
   *   The bundle ids.
   */
  public function getBundles(): array;

  /**
   * Returns whether a media entity is tracked by the workflow.
   */
  public function appliesTo(MediaInterface $media): bool;

  /**
   * Returns the current state of a tracked media entity.
   *
   * An empty field reads as scheduled.
   */
  public function getState(MediaInterface $media): string;

  /**
   * Returns whether a state is a final one (done or error).
   */
  public function isSettled(string $state): bool;

}
