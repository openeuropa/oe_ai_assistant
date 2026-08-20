<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\DocumentFile;
use Drupal\Core\DependencyInjection\Attribute\Autowire;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Document\ContextDocumentStorage;
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
   * Media UUIDs currently being summarised by this service instance.
   *
   * @var array<string, bool>
   */
  private array $activeExtractions = [];

  public function __construct(
    private readonly AiProviderPluginManager $aiProviderManager,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(MediaInterface $media): bool {
    $details = $this->getStorageDetails($media);
    return $details !== NULL
      && $media->hasField($details['sourceField'])
      && $media->hasField($details['summaryField']);
  }

  /**
   * {@inheritdoc}
   */
  public function extractAndSave(MediaInterface $media): string {
    $details = $this->getStorageDetails($media);
    if ($details === NULL) {
      throw new \RuntimeException(sprintf(
        'Document summary extraction does not support media bundle "%s".',
        $media->bundle(),
      ));
    }

    $key = $this->getExtractionKey($media);
    if (isset($this->activeExtractions[$key])) {
      return $this->getStoredSummary($media, $details['summaryField']);
    }

    $this->activeExtractions[$key] = TRUE;
    try {
      $file = $this->getSourceFile($media, $details['sourceField']);
      $summary = $this->requestSummary($file);
      $media->set($details['summaryField'], [
        'value' => $summary,
      ]);
      $media->save();

      return $summary;
    }
    catch (\Throwable $e) {
      $this->logger->error('Document summary extraction failed for media @media_id (@bundle), file @file_id (@filename): @message', [
        '@media_id' => $media->id() ?? 'new',
        '@bundle' => $media->bundle(),
        '@file_id' => isset($file) ? $file->id() : 'unknown',
        '@filename' => isset($file) ? $file->getFilename() : 'unknown',
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
    finally {
      unset($this->activeExtractions[$key]);
    }
  }

  /**
   * Returns configured storage details for a supported media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return array{category: string, sourceField: string, summaryField: string}|null
   *   Storage details, or NULL for unsupported bundles.
   */
  private function getStorageDetails(MediaInterface $media): ?array {
    $bundles = ContextDocumentStorage::workingMaterialBundles();
    return $bundles[$media->bundle()] ?? NULL;
  }

  /**
   * Gets the source file from the configured media source field.
   */
  private function getSourceFile(MediaInterface $media, string $sourceField): FileInterface {
    if (!$media->hasField($sourceField) || $media->get($sourceField)->isEmpty()) {
      throw new \RuntimeException(sprintf(
        'Document media "%s" has no source file.',
        $media->id() ?? 'new',
      ));
    }

    $file = $media->get($sourceField)->entity;
    if (!$file instanceof FileInterface) {
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
  private function requestSummary(FileInterface $file): string {
    $defaults = $this->aiProviderManager
      ->getDefaultProviderForOperationType(self::OPERATION_TYPE);
    if (empty($defaults['provider_id']) || empty($defaults['model_id'])) {
      throw new \RuntimeException(sprintf(
        'No default AI provider is configured for "%s".',
        self::OPERATION_TYPE,
      ));
    }

    $binary = file_get_contents($file->getFileUri());
    if ($binary === FALSE) {
      throw new \RuntimeException(sprintf(
        'The document file "%s" could not be read.',
        $file->getFilename(),
      ));
    }

    $message = new ChatMessage(
      'user',
      'Summarise the attached document for an editor who will use it as temporary briefing context. Return only the summary.',
      [
        new DocumentFile(
          $binary,
          $this->resolveMimeType($file),
          $file->getFilename(),
        ),
      ],
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
   * Resolves a stable MIME type for provider file input.
   */
  private function resolveMimeType(FileInterface $file): string {
    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    return match ($extension) {
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'md' => 'text/markdown',
      'pdf' => 'application/pdf',
      'txt' => 'text/plain',
      default => $file->getMimeType() ?: 'application/octet-stream',
    };
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
