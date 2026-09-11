<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\media\MediaInterface;

/**
 * Drives one session document through extraction and summarisation.
 */
interface DocumentExtractionProcessorInterface {

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

}
