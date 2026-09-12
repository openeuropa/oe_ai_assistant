<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinition;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\UploadedFileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Hook\DocumentMediaHooks;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Base implementation of the session document lifecycle.
 *
 * A document is a media entity that references its editorial session, the
 * way a conversation message references its host. Adding a document only
 * creates a media entity and removing one only deletes it, so concurrent
 * uploads never write the same row and need no lock. Concrete repositories
 * only declare the storage details of their document category (media
 * bundle, source field, category name).
 */
abstract class DocumentRepositoryBase implements DocumentRepositoryInterface {

  /**
   * The media field referencing the owning editorial session.
   */
  public const string SESSION_FIELD = 'oe_ai_session';

  /**
   * Constructs the repository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\file\Upload\FileUploadHandlerInterface $fileUploadHandler
   *   The file upload handler service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionWorkflowInterface $workflow
   *   The extraction workflow reader, for the document status.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface $processor
   *   The extraction processor behind the extract action.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly FileUploadHandlerInterface $fileUploadHandler,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    protected readonly LoggerInterface $logger,
    protected readonly DocumentExtractionWorkflowInterface $workflow,
    protected readonly DocumentExtractionProcessorInterface $processor,
  ) {}

  /**
   * Gets the media bundle used for documents of this category.
   */
  abstract protected function getMediaBundle(): string;

  /**
   * Gets the media source field that stores the document file.
   */
  abstract protected function getSourceField(): string;

  /**
   * Gets the API document category served by the repository.
   */
  abstract protected function getCategory(): string;

  /**
   * Builds a bare source field item to read upload settings from.
   *
   * The file field type encapsulates the upload destination
   * (getUploadLocation) and the configured validators
   * (getUploadValidators). Building a bare item from the field
   * definition gives access to that API without creating an entity,
   * exactly as core's FileUploadLocationTrait does, so API uploads
   * behave like uploads through the field widget.
   *
   * @return \Drupal\file\Plugin\Field\FieldType\FileItem
   *   A bare item for the source field.
   */
  private function getSourceFieldItem(): FileItem {
    $definition = $this->entityTypeManager->getStorage('field_config')
      ->load('media.' . $this->getMediaBundle() . '.' . $this->getSourceField());

    return new FileItem(FieldItemDataDefinition::create($definition));
  }

  /**
   * {@inheritdoc}
   */
  public function add(AiEditorialSessionInterface $session, UploadedFileInterface $upload): array {
    // The file stays temporary until the media that owns it is saved: the
    // media's file field records a usage, and that flips it to permanent.
    // A failure before that leaves a temporary file cron reaps on its own.
    $managedFile = $this->saveUploadedFile($upload);

    try {
      // Name the media after the stored file: core may have renamed the
      // upload, for example to neutralise an insecure double extension.
      $media = $this->createMedia($session, $managedFile, $managedFile->getFilename());
    }
    catch (\Throwable $e) {
      $managedFile->delete();
      throw $e;
    }

    return $this->serialize($media);
  }

  /**
   * {@inheritdoc}
   */
  public function list(AiEditorialSessionInterface $session): array {
    return array_map($this->serialize(...), $this->loadAll($session));
  }

  /**
   * {@inheritdoc}
   */
  public function remove(AiEditorialSessionInterface $session, string $documentId): void {
    $this->deleteDocument($this->loadOwned($session, $documentId));
  }

  /**
   * {@inheritdoc}
   */
  public function describe(AiEditorialSessionInterface $session): array {
    $documents = [];
    foreach ($this->loadAll($session) as $media) {
      $extract = trim((string) ($media->get(DocumentMediaHooks::EXTRACT_FIELD)->value ?? ''));
      $documents[] = $this->serialize($media) + [
        'category' => $this->getCategory(),
        'summary' => trim((string) ($media->get(DocumentMediaHooks::SUMMARY_FIELD)->value ?? '')),
        'extract' => $extract === '' ? NULL : $extract,
      ];
    }

    return $documents;
  }

  /**
   * {@inheritdoc}
   */
  public function extract(AiEditorialSessionInterface $session, string $documentId): string {
    return $this->processor->process($this->loadOwned($session, $documentId));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteForSession(AiEditorialSessionInterface $session): void {
    foreach ($this->loadAll($session) as $media) {
      $this->deleteDocument($media);
    }
  }

  /**
   * Loads the documents of a session in upload order.
   *
   * @return \Drupal\media\MediaInterface[]
   *   The document media entities.
   */
  private function loadAll(AiEditorialSessionInterface $session): array {
    if ($session->isNew()) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('media');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $this->getMediaBundle())
      ->condition(self::SESSION_FIELD, (int) $session->id())
      ->sort('mid')
      ->execute();

    return array_values(array_filter(
      $storage->loadMultiple($ids),
      static fn($media) => $media instanceof MediaInterface,
    ));
  }

  /**
   * Loads a document only when it belongs to the session.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the document does not exist or belongs elsewhere.
   */
  private function loadOwned(AiEditorialSessionInterface $session, string $documentId): MediaInterface {
    $media = $this->entityTypeManager->getStorage('media')->load($documentId);
    if ($media instanceof MediaInterface
      && $media->bundle() === $this->getMediaBundle()
      && (string) $media->get(self::SESSION_FIELD)->target_id === (string) $session->id()
    ) {
      return $media;
    }
    throw new ActionException(
      'invalid_request',
      'The document does not belong to this editorial session.',
      404,
    );
  }

  /**
   * Serializes a document media entity for API and UI bootstrap.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media entity.
   *
   * @return array
   *   The serialized document item.
   */
  protected function serialize(MediaInterface $media): array {
    $file = $this->getFile($media);
    $filename = $file?->getFilename() ?: $media->label();
    $extension = pathinfo($filename, PATHINFO_EXTENSION);

    return [
      'id' => (string) $media->id(),
      'title' => (string) ($media->label() ?: $filename),
      'status' => $this->workflow->getState($media),
      'meta' => [
        'type' => $extension !== '' ? strtolower($extension) : 'file',
        'size' => $file instanceof FileInterface ? (int) $file->getSize() : 0,
      ],
    ];
  }

  /**
   * Saves an uploaded document as a managed file.
   *
   * @param \Drupal\file\Upload\UploadedFileInterface $upload
   *   The uploaded file.
   *
   * @return \Drupal\file\FileInterface
   *   The managed file entity.
   */
  private function saveUploadedFile(UploadedFileInterface $upload): FileInterface {
    // Destination and validators come from the source field configuration,
    // through the same field type API the file widget uses.
    $item = $this->getSourceFieldItem();
    $directory = $item->getUploadLocation();
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      $this->discardUpload($upload);
      throw new ActionException(
        'upload_failed',
        'The private document directory could not be prepared.',
        500,
      );
    }

    try {
      $result = $this->fileUploadHandler->handleFileUpload(
        $upload,
        $item->getUploadValidators(),
        $directory,
        FileExists::Rename,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Document upload failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->discardUpload($upload);
      throw new ActionException(
        'upload_failed',
        'The uploaded document could not be saved.',
        500,
      );
    }

    if ($result->hasViolations()) {
      $messages = [];
      foreach ($result->getViolations() as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      $this->discardUpload($upload);
      throw new ActionException(
        'invalid_request',
        implode(' ', $messages),
        400,
      );
    }

    return $result->getFile();
  }

  /**
   * Removes the staged copy of a rejected upload.
   *
   * A rejected upload never becomes a managed file, so nothing else cleans
   * up the temporary copy the request body was staged to.
   *
   * @param \Drupal\file\Upload\UploadedFileInterface $upload
   *   The rejected upload.
   */
  private function discardUpload(UploadedFileInterface $upload): void {
    $path = $upload->getRealPath();
    if ($path !== FALSE && file_exists($path)) {
      $this->fileSystem->unlink($path);
    }
  }

  /**
   * Creates the document media entity for a managed file.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session that owns the document.
   * @param \Drupal\file\FileInterface $file
   *   The managed file entity.
   * @param string $name
   *   The media name, taken from the stored filename.
   *
   * @return \Drupal\media\MediaInterface
   *   The saved media entity.
   */
  private function createMedia(AiEditorialSessionInterface $session, FileInterface $file, string $name): MediaInterface {
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $this->getMediaBundle(),
      'name' => $name,
      'status' => 0,
      self::SESSION_FIELD => ['target_id' => $session->id()],
      $this->getSourceField() => [
        'target_id' => $file->id(),
        'entity' => $file,
      ],
    ]);
    // The upload validators configured on the source field already ran in
    // saveUploadedFile(); this validates the reference itself.
    $violations = $media->get($this->getSourceField())->validate();
    if ($violations->count() > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      throw new ActionException(
        'invalid_request',
        implode(' ', $messages),
        400,
      );
    }

    $media->save();

    if (!$media instanceof MediaInterface) {
      throw new ActionException(
        'upload_failed',
        'The uploaded document could not be saved.',
        500,
      );
    }

    return $media;
  }

  /**
   * Deletes a document media entity together with its managed file.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media entity.
   */
  private function deleteDocument(MediaInterface $media): void {
    $file = $this->getFile($media);
    $media->delete();
    $file?->delete();
  }

  /**
   * Gets the file referenced by a document media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The referenced file, if available.
   */
  private function getFile(MediaInterface $media): ?FileInterface {
    if (!$media->hasField($this->getSourceField())) {
      return NULL;
    }

    $file = $media->get($this->getSourceField())->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

}
