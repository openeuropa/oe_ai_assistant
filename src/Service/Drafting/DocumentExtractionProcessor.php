<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Exception\DocumentExtractionException;
use Drupal\oe_ai_assistant\Hook\DocumentMediaHooks;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Processor persisting every step on the media entity.
 *
 * Saves never create a revision: the pipeline is not editorial history.
 */
final class DocumentExtractionProcessor implements DocumentExtractionProcessorInterface {

  public function __construct(
    private readonly DocumentExtractionWorkflowInterface $workflow,
    private readonly DocumentTextExtractorInterface $extractor,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(MediaInterface $media, bool $reclaimInFlight = FALSE): string {
    if (!$this->workflow->appliesTo($media)) {
      return $this->workflow->getState($media);
    }
    $claimed = $this->claim($media, $reclaimInFlight);
    if ($claimed === NULL) {
      return $this->workflow->getState($this->reload($media));
    }

    return $this->run($claimed);
  }

  /**
   * Moves a resting document into the in-flight state of its next step.
   *
   * The check and the transition happen on a fresh copy inside a lock, so
   * two callers never both claim the same document.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The claimed document, or NULL when nothing was claimed.
   */
  private function claim(MediaInterface $media, bool $reclaimInFlight): ?MediaInterface {
    $name = 'oe_ai_assistant_extraction_' . $media->id();
    if (!$this->lock->acquire($name)) {
      return NULL;
    }
    try {
      $fresh = $this->reload($media);
      $state = $this->workflow->getState($fresh);
      $inFlight = in_array($state, [
        DocumentExtractionWorkflowInterface::STATE_EXTRACTING,
        DocumentExtractionWorkflowInterface::STATE_SUMMARIZING,
      ], TRUE);
      if ($state === DocumentExtractionWorkflowInterface::STATE_DONE || ($inFlight && !$reclaimInFlight)) {
        return NULL;
      }
      $next = $fresh->get(DocumentMediaHooks::EXTRACT_FIELD)->isEmpty()
        ? DocumentExtractionWorkflowInterface::STATE_EXTRACTING
        : DocumentExtractionWorkflowInterface::STATE_SUMMARIZING;
      $this->saveState($fresh, $next);

      return $fresh;
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Runs the remaining steps of a claimed document.
   */
  private function run(MediaInterface $media): string {
    try {
      if ($this->workflow->getState($media) === DocumentExtractionWorkflowInterface::STATE_EXTRACTING) {
        $text = $this->extractor->extract($this->getSourceFile($media));
        $media->set(DocumentMediaHooks::EXTRACT_FIELD, $text);
        $this->saveState($media, DocumentExtractionWorkflowInterface::STATE_EXTRACTED);
        $this->saveState($media, DocumentExtractionWorkflowInterface::STATE_SUMMARIZING);
      }

      $summary = $this->summarize((string) $media->get(DocumentMediaHooks::EXTRACT_FIELD)->value);
      $media->set(DocumentMediaHooks::SUMMARY_FIELD, ['value' => $summary]);
      $this->saveState($media, DocumentExtractionWorkflowInterface::STATE_DONE);

      return DocumentExtractionWorkflowInterface::STATE_DONE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Document @id extraction failed: @message', [
        '@id' => $media->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->saveState($media, DocumentExtractionWorkflowInterface::STATE_ERROR);

      return DocumentExtractionWorkflowInterface::STATE_ERROR;
    }
  }

  /**
   * Writes a brief summary of the extract; completed in the next task.
   */
  private function summarize(string $text): string {
    throw new DocumentExtractionException('Summarisation is not implemented.');
  }

  /**
   * Sets a state and saves without creating a revision.
   */
  private function saveState(MediaInterface $media, string $state): void {
    $media->set(DocumentExtractionWorkflowInterface::STATE_FIELD, $state);
    $media->setNewRevision(FALSE);
    $media->save();
  }

  /**
   * Returns the media source file or fails the run.
   */
  private function getSourceFile(MediaInterface $media): FileInterface {
    $sourceField = $media->getSource()->getConfiguration()['source_field'] ?? '';
    $file = $sourceField !== '' ? $media->get($sourceField)->entity : NULL;
    if (!$file instanceof FileInterface) {
      throw new DocumentExtractionException('The document has no source file.');
    }

    return $file;
  }

  /**
   * Loads a fresh copy of the media entity.
   */
  private function reload(MediaInterface $media): MediaInterface {
    $storage = $this->entityTypeManager->getStorage('media');
    $storage->resetCache([$media->id()]);
    $fresh = $storage->load($media->id());

    return $fresh instanceof MediaInterface ? $fresh : $media;
  }

}
