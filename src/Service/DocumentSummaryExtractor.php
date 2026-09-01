<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\DocumentFile;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Document\ContextDocumentStorage;
use Drupal\oe_ai_assistant\Exception\DocumentSummaryExtractionException;
use Psr\Log\LoggerInterface;

/**
 * Extracts summaries from document media through the configured AI provider.
 */
class DocumentSummaryExtractor implements DocumentSummaryExtractorInterface {

  /**
   * The AI operation type used for multimodal document summarisation.
   */
  private const string OPERATION_TYPE = 'chat_with_image_vision';

  /**
   * Maximum source document size sent directly to the provider.
   */
  private const int MAX_DOCUMENT_BYTES = 20971520;

  /**
   * Media UUIDs currently being summarised by this service instance.
   *
   * @var array<string, bool>
   */
  private array $activeExtractions = [];

  public function __construct(
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(MediaInterface $media): bool {
    $details = ContextDocumentStorage::workingMaterialBundle($media->bundle());
    return $details !== NULL
      && $media->hasField($details['sourceField'])
      && $media->hasField($details['summaryField']);
  }

  /**
   * {@inheritdoc}
   */
  public function isExtracting(MediaInterface $media): bool {
    return isset($this->activeExtractions[$this->getExtractionKey($media)]);
  }

  /**
   * {@inheritdoc}
   */
  public function extract(MediaInterface $media): string {
    $details = ContextDocumentStorage::workingMaterialBundle($media->bundle());
    if ($details === NULL) {
      throw new \RuntimeException(sprintf(
        'Document summary extraction does not support media bundle "%s".',
        $media->bundle(),
      ));
    }

    $key = $this->getExtractionKey($media);
    if ($this->isExtracting($media)) {
      return $this->getStoredSummary($media, $details['summaryField']);
    }

    $this->activeExtractions[$key] = TRUE;
    try {
      return $this->extractSummary($media, $details);
    }
    catch (\Throwable $e) {
      $this->clearSummary($media, $details['summaryField'], FALSE);
      $this->logExtractionFailure($media, $details['sourceField'], $e);
      throw new DocumentSummaryExtractionException(
        'The document summary could not be extracted.',
        0,
        $e,
      );
    }
    finally {
      unset($this->activeExtractions[$key]);
    }
  }

  /**
   * Logs a failed extraction with source-file context when available.
   */
  private function logExtractionFailure(MediaInterface $media, string $sourceField, \Throwable $e): void {
    $file = NULL;
    if ($media->hasField($sourceField) && !$media->get($sourceField)->isEmpty()) {
      $file = $media->get($sourceField)->entity;
    }

    $this->logger->error('Document summary extraction failed for media @media_id (@bundle), file @file_id (@filename): @message', [
      '@media_id' => $media->id() ?? 'new',
      '@bundle' => $media->bundle(),
      '@file_id' => $file instanceof FileInterface ? $file->id() : 'unknown',
      '@filename' => $file instanceof FileInterface ? $file->getFilename() : 'unknown',
      '@message' => $e->getMessage(),
    ]);
  }

  /**
   * Extracts a summary for the configured media source field.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param array{category: string, sessionField: string, mediaBundle: string, sourceField: string, summaryField: string} $details
   *   Storage details for the media bundle.
   */
  private function extractSummary(MediaInterface $media, array $details): string {
    $file = $this->getSourceFile($media, $details['sourceField']);
    return $this->requestSummary($file);
  }

  /**
   * Gets the source file from the configured media source field.
   */
  private function getSourceFile(MediaInterface $media, string $sourceField): File {
    if (!$media->hasField($sourceField) || $media->get($sourceField)->isEmpty()) {
      throw new \RuntimeException(sprintf(
        'Document media "%s" has no source file.',
        $media->id() ?? 'new',
      ));
    }

    $file = $media->get($sourceField)->entity;
    if (!$file instanceof File) {
      throw new \RuntimeException(sprintf(
        'Document media "%s" source field does not reference a managed file.',
        $media->id() ?? 'new',
      ));
    }

    return $file;
  }

  /**
   * Requests a document summary from the configured multimodal provider.
   */
  private function requestSummary(File $file): string {
    $defaults = $this->aiProviderManager
      ->getDefaultProviderForOperationType(self::OPERATION_TYPE);
    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      throw new \RuntimeException(sprintf(
        'No default AI provider is configured for "%s".',
        self::OPERATION_TYPE,
      ));
    }

    $this->assertSupportedFileSize($file);
    $documentFile = new DocumentFile();
    $documentFile->setFileFromFile($file);
    $this->applyMimeTypeOverrides($documentFile);

    $message = new ChatMessage(
      'user',
      'Summarise the attached document for an editor who will use it as temporary briefing context. Return only the summary.',
      [$documentFile],
    );
    $input = new ChatInput([$message]);
    $input->setSystemPrompt(
      'You extract concise editorial briefing summaries from uploaded source documents. Do not rewrite the document as publishable content.'
    );

    $provider = $this->aiProviderManager->createInstance($defaults['provider_id']);
    $output = $provider->chat($input, $defaults['model_id'], [
      'document_summary',
      'oe_ai_assistant',
    ]);
    $normalized = $output->getNormalized();
    if (!$normalized instanceof ChatMessage) {
      throw new \RuntimeException('The AI provider returned an unsupported streamed summary response.');
    }

    $summary = trim($normalized->getText());
    if ($summary === '') {
      throw new \RuntimeException('The AI provider returned an empty document summary.');
    }

    return $summary;
  }

  /**
   * Applies MIME overrides for formats Drupal guesses too generically.
   */
  private function applyMimeTypeOverrides(DocumentFile $file): void {
    if (strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION)) === 'md') {
      $file->setMimeType('text/markdown');
    }
  }

  /**
   * Ensures the direct provider payload remains within the MVP size limit.
   */
  private function assertSupportedFileSize(FileInterface $file): void {
    $size = (int) $file->getSize();
    if ($size > self::MAX_DOCUMENT_BYTES) {
      throw new \RuntimeException(sprintf(
        'The document file "%s" is too large to summarise synchronously.',
        $file->getFilename(),
      ));
    }
  }

  /**
   * Clears any stale summary after a failed extraction.
   */
  private function clearSummary(MediaInterface $media, string $summaryField, bool $save): void {
    if (!$media->hasField($summaryField) || $media->get($summaryField)->isEmpty()) {
      return;
    }

    $media->set($summaryField, NULL);
    if (!$save) {
      return;
    }

    try {
      $media->save();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Document summary cleanup failed for media @media_id: @message', [
        '@media_id' => $media->id() ?? 'new',
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Builds a stable key used to prevent recursive extraction during media save.
   */
  private function getExtractionKey(MediaInterface $media): string {
    return $media->uuid() ?: (string) spl_object_id($media);
  }

  /**
   * Returns the currently stored summary for recursive calls.
   */
  private function getStoredSummary(MediaInterface $media, string $summaryField): string {
    if (!$media->hasField($summaryField) || $media->get($summaryField)->isEmpty()) {
      return '';
    }

    return trim((string) $media->get($summaryField)->value);
  }

}
