<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\document_loader\DocumentLoaderType\Input\MarkdownInput;
use Drupal\document_loader\DocumentLoaderType\Input\PdfInput;
use Drupal\document_loader\DocumentLoaderType\Input\TextInput;
use Drupal\document_loader\DocumentLoaderType\Input\WordInput;
use Drupal\document_loader\Service\DocumentLoaderManager;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Exception\DocumentExtractionException;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Processor persisting every step on the media entity.
 *
 * State changes go through the transitions of the oe_ai_document_extraction
 * workflow, so state_machine guards every move. Text comes from the
 * document_loader framework; saves never create a revision.
 */
final class DocumentExtractionProcessor implements DocumentExtractionProcessorInterface {

  /**
   * Accepted extensions mapped to their document_loader type and input class.
   *
   * @var array
   */
  private const array TYPES = [
    'txt' => ['text', TextInput::class],
    'md' => ['markdown', MarkdownInput::class],
    'docx' => ['word', WordInput::class],
    'doc' => ['word', WordInput::class],
    'pdf' => ['pdf', PdfInput::class],
  ];

  /**
   * Word bookmark markers Tika leaves in plain text, such as [bookmark: _Toc0].
   */
  private const string BOOKMARK_PATTERN = '/\[bookmark: [^\]]*\]/';

  /**
   * Upper bound of extract characters sent to the model.
   */
  private const int MAX_PROMPT_CHARS = 60000;

  /**
   * System prompt of the summary call.
   */
  private const string SUMMARY_PROMPT = 'You summarise briefing documents for editors. '
    . 'Write a brief summary of the document in English, three to five sentences: '
    . 'what it is and its key points. Return only the summary.';

  public function __construct(
    #[Autowire(service: 'document_loader.manager')]
    private readonly DocumentLoaderManager $loader,
    private readonly AccountProxyInterface $currentUser,
    #[Autowire(service: 'ai.provider')]
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function schedule(MediaInterface $media): void {
    $media->set(self::EXTRACT_FIELD, NULL);
    $media->set(self::SUMMARY_FIELD, NULL);
    $state = $this->stateItem($media);
    // A document already at rest in scheduled needs no transition.
    if ($state->getId() !== self::STATE_SCHEDULED) {
      $state->applyTransitionById('reschedule');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function process(MediaInterface $media, bool $reclaimInFlight = FALSE): string {
    $claimed = $this->claim($media, $reclaimInFlight);
    if ($claimed === NULL) {
      return $this->getState($this->reload($media) ?? $media);
    }

    return $this->run($claimed);
  }

  /**
   * {@inheritdoc}
   */
  public function processPending(int $limit, int $staleAfterSeconds): void {
    $storage = $this->entityTypeManager->getStorage('media');
    // Resting documents first: they are waiting for a step and are never
    // owned by another run. The flag says whether the claim may take over
    // an in-flight document.
    $pending = [];
    foreach ($this->findInStates([self::STATE_SCHEDULED, self::STATE_EXTRACTED], $limit) as $id) {
      $pending[$id] = FALSE;
    }
    // Fill the rest of the batch with in-flight documents nobody touched
    // for a while: their run crashed or was killed, so they are reclaimed.
    $remaining = $limit - count($pending);
    if ($remaining > 0) {
      $threshold = $this->time->getRequestTime() - $staleAfterSeconds;
      foreach ($this->findInStates([self::STATE_EXTRACTING, self::STATE_SUMMARIZING], $remaining, $threshold) as $id) {
        $pending[$id] = TRUE;
      }
    }
    foreach ($pending as $id => $reclaim) {
      $media = $storage->load($id);
      if ($media instanceof MediaInterface) {
        $this->process($media, $reclaim);
      }
    }
  }

  /**
   * Returns the ids of documents in the given states, oldest first.
   *
   * @param string[] $states
   *   The states to match.
   * @param int $limit
   *   Maximum number of ids.
   * @param int|null $changedBefore
   *   When set, only documents last changed before this timestamp.
   *
   * @return string[]
   *   The media ids.
   */
  private function findInStates(array $states, int $limit, ?int $changedBefore = NULL): array {
    $query = $this->entityTypeManager->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition(self::STATE_FIELD, $states, 'IN')
      ->sort('mid')
      ->range(0, $limit);
    if ($changedBefore !== NULL) {
      $query->condition('changed', $changedBefore, '<');
    }

    return array_values($query->execute());
  }

  /**
   * Reads the state of a document, scheduled when none is stored yet.
   */
  private function getState(MediaInterface $media): string {
    return (string) ($media->get(self::STATE_FIELD)->value ?? '') ?: self::STATE_SCHEDULED;
  }

  /**
   * Moves a document into the in-flight state of its next step.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The claimed document, or NULL when nothing was claimed.
   */
  private function claim(MediaInterface $media, bool $reclaimInFlight): ?MediaInterface {
    // The check and the transition run on a fresh copy under a lock, so two
    // callers never both claim the same document.
    $name = 'oe_ai_assistant_extraction_' . $media->id();
    if (!$this->lock->acquire($name)) {
      return NULL;
    }
    try {
      $fresh = $this->reload($media);
      if ($fresh === NULL) {
        return NULL;
      }
      $state = $this->getState($fresh);
      $inFlight = in_array($state, [
        self::STATE_EXTRACTING,
        self::STATE_SUMMARIZING,
      ], TRUE);
      if ($state === self::STATE_DONE || ($inFlight && !$reclaimInFlight)) {
        return NULL;
      }
      // A stale in-flight document goes back to scheduled first, since
      // the claim transitions only leave a resting state.
      if ($inFlight) {
        $this->transition($fresh, 'reschedule');
      }
      // Resume by data: a stored extract only needs the summary step.
      $this->transition($fresh, $fresh->get(self::EXTRACT_FIELD)->isEmpty() ? 'claim_extract' : 'claim_summarize');

      return $fresh;
    }
    catch (\Throwable $e) {
      // Removal can land after the reload but before the transition saves:
      // leave the gone document alone instead of aborting the batch.
      if ($this->isDeleted($media)) {
        return NULL;
      }
      throw $e;
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Runs the remaining steps of a claimed document.
   */
  private function run(MediaInterface $media): string {
    // The editor can delete the document while a step runs, so every write
    // is preceded by an existence check and a deleted document ends the
    // run quietly with the state it had reached.
    try {
      if ($this->getState($media) === self::STATE_EXTRACTING) {
        $text = $this->extractText($this->getSourceFile($media));
        if ($this->isDeleted($media)) {
          return self::STATE_EXTRACTING;
        }
        $media->set(self::EXTRACT_FIELD, $text);
        $this->transition($media, 'extracted');
        $this->transition($media, 'claim_summarize');
      }

      $summary = $this->summarize((string) $media->get(self::EXTRACT_FIELD)->value);
      if ($this->isDeleted($media)) {
        return self::STATE_SUMMARIZING;
      }
      $media->set(self::SUMMARY_FIELD, ['value' => $summary]);
      $this->transition($media, 'done');

      return self::STATE_DONE;
    }
    catch (\Throwable $e) {
      if ($this->isDeleted($media)) {
        return $this->getState($media);
      }
      $this->logger->error('Document @id extraction failed: @message', [
        '@id' => $media->id(),
        '@message' => $e->getMessage(),
      ]);
      // Only the in-flight states can fail; a failure between two saves
      // leaves the document at rest for the next run.
      if ($this->stateItem($media)->isTransitionAllowed('fail')) {
        $this->transition($media, 'fail');
      }

      return $this->getState($media);
    }
  }

  /**
   * Extracts the plain text of a document file through document_loader.
   *
   * The typed input skips the framework's file access check, which the
   * anonymous cron user would fail on a private file.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\DocumentExtractionException
   *   When the extension is unsupported, the loader fails, or no text
   *   comes back.
   */
  private function extractText(FileInterface $file): string {
    $extension = strtolower(pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));
    if (!isset(self::TYPES[$extension])) {
      throw new DocumentExtractionException(sprintf('Unsupported document extension "%s".', $extension));
    }
    [$type, $inputClass] = self::TYPES[$extension];

    try {
      $result = $this->loader->loadFromInput(
        'document_loader_type:' . $type,
        new $inputClass((string) $file->getFileUri()),
        $this->currentUser,
        'text',
      );
    }
    catch (\Throwable $e) {
      throw new DocumentExtractionException('Text extraction failed: ' . $e->getMessage(), 0, $e);
    }

    $text = trim((string) preg_replace(self::BOOKMARK_PATTERN, '', $result->content));
    if ($text === '') {
      throw new DocumentExtractionException('Text extraction returned no text.');
    }

    return $text;
  }

  /**
   * Writes a brief summary of the extract with the default chat provider.
   *
   * The call is not streamed and the extract is capped so a long document
   * never overflows the model context.
   */
  private function summarize(string $text): string {
    $defaults = $this->aiProviderManager->getDefaultProviderForOperationType('chat');
    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      throw new DocumentExtractionException('No default chat provider is configured.');
    }
    $provider = $this->aiProviderManager->createInstance($defaults['provider_id']);

    $input = new ChatInput([new ChatMessage('user', mb_substr($text, 0, self::MAX_PROMPT_CHARS))]);
    $input->setSystemPrompt(self::SUMMARY_PROMPT);
    $output = $provider->chat($input, $defaults['model_id'], ['oe_ai_assistant', 'document_summary']);

    $summary = trim($output->getNormalized()->getText());
    if ($summary === '') {
      throw new DocumentExtractionException('The provider returned an empty summary.');
    }

    return $summary;
  }

  /**
   * Applies a workflow transition and saves without creating a revision.
   */
  private function transition(MediaInterface $media, string $transitionId): void {
    $this->stateItem($media)->applyTransitionById($transitionId);
    $media->setNewRevision(FALSE);
    $media->save();
  }

  /**
   * Returns the state field item, created in scheduled when still empty.
   */
  private function stateItem(MediaInterface $media): StateItemInterface {
    $field = $media->get(self::STATE_FIELD);
    if ($field->isEmpty()) {
      $field->setValue(self::STATE_SCHEDULED);
    }

    return $field->first();
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
   * Checks whether the document was deleted since it was loaded.
   */
  private function isDeleted(MediaInterface $media): bool {
    return $this->reload($media) === NULL;
  }

  /**
   * Loads a fresh copy of the media entity, NULL when it no longer exists.
   */
  private function reload(MediaInterface $media): ?MediaInterface {
    $storage = $this->entityTypeManager->getStorage('media');
    $storage->resetCache([$media->id()]);
    $fresh = $storage->load($media->id());

    return $fresh instanceof MediaInterface ? $fresh : NULL;
  }

}
