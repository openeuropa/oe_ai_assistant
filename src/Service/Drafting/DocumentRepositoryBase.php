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
use Drupal\file\Upload\FormUploadedFile;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\file\Upload\UploadedFileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Base implementation of the session document lifecycle.
 *
 * Implements the add, list, remove and orphan cleanup operations once.
 * Concrete repositories only declare the storage details of their document
 * category (media bundle, source field, session reference field, upload
 * directory), keeping those details out of the rest of the codebase.
 */
abstract class DocumentRepositoryBase implements DocumentRepositoryInterface {

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
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly FileUploadHandlerInterface $fileUploadHandler,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets the session field that references documents of this category.
   */
  abstract protected function getSessionField(): string;

  /**
   * Gets the media bundle used for documents of this category.
   */
  abstract protected function getMediaBundle(): string;

  /**
   * Gets the media source field that stores the document file.
   */
  abstract protected function getSourceField(): string;

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
  public function add(AiEditorialSessionInterface $session, UploadedFile $upload): array {
    $managedFile = $this->saveUploadedFile($upload);
    $managedFile->setPermanent();
    $managedFile->save();

    $media = NULL;
    try {
      $media = $this->createMedia($managedFile, $upload);
      $session->get($this->getSessionField())->appendItem([
        'target_id' => $media->id(),
      ]);
      $session->save();
    }
    catch (\Throwable $e) {
      if ($media instanceof MediaInterface) {
        $media->delete();
      }
      $managedFile->delete();
      throw $e;
    }

    return $this->serialize($media);
  }

  /**
   * {@inheritdoc}
   */
  public function list(AiEditorialSessionInterface $session): array {
    $documents = [];
    foreach ($session->get($this->getSessionField())->referencedEntities() as $media) {
      if ($media instanceof MediaInterface) {
        $documents[] = $this->serialize($media);
      }
    }

    return $documents;
  }

  /**
   * {@inheritdoc}
   */
  public function remove(AiEditorialSessionInterface $session, string $documentId): void {
    $field = $session->get($this->getSessionField());
    $referenced = FALSE;
    foreach ($field as $delta => $item) {
      if ((string) $item->target_id === $documentId) {
        $field->removeItem($delta);
        $referenced = TRUE;
        break;
      }
    }

    if (!$referenced) {
      throw new ActionException(
        'invalid_request',
        'The document is not referenced by this editorial session.',
        404,
      );
    }

    $media = $this->entityTypeManager->getStorage('media')->load($documentId);
    $session->save();
    if ($media instanceof MediaInterface) {
      $this->deleteDocument($media);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteOrphanedBy(AiEditorialSessionInterface $session): void {
    if (!$session->hasField($this->getSessionField())) {
      return;
    }

    $document_ids = [];
    foreach ($session->get($this->getSessionField()) as $item) {
      if ($item->target_id !== NULL) {
        $document_ids[] = (int) $item->target_id;
      }
    }
    $document_ids = array_values(array_unique($document_ids));
    if ($document_ids === []) {
      return;
    }

    $media_storage = $this->entityTypeManager->getStorage('media');
    foreach ($media_storage->loadMultiple($document_ids) as $media) {
      if (!$media instanceof MediaInterface
        || $media->bundle() !== $this->getMediaBundle()
        || $this->isReferencedByAnotherSession((int) $media->id(), (int) $session->id())
      ) {
        continue;
      }

      $this->deleteDocument($media);
    }
  }

  /**
   * Serializes a document media entity for API and UI bootstrap.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media entity.
   *
   * @return array<string, string|array<string, string|int>>
   *   The serialized document item.
   */
  protected function serialize(MediaInterface $media): array {
    $file = $this->getFile($media);
    $filename = $file?->getFilename() ?: $media->label();
    $extension = pathinfo($filename, PATHINFO_EXTENSION);

    return [
      'id' => (string) $media->id(),
      'title' => (string) ($media->label() ?: $filename),
      'meta' => [
        'type' => $extension !== '' ? strtolower($extension) : 'file',
        'size' => $file instanceof FileInterface ? (int) $file->getSize() : 0,
      ],
    ];
  }

  /**
   * Saves an uploaded document as a managed file.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The uploaded file.
   *
   * @return \Drupal\file\FileInterface
   *   The managed file entity.
   */
  private function saveUploadedFile(UploadedFile $upload): FileInterface {
    // Destination and validators come from the source field configuration,
    // through the same field type API the file widget uses.
    $item = $this->getSourceFieldItem();
    $directory = $item->getUploadLocation();
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      throw new ActionException(
        'upload_failed',
        'The private document directory could not be prepared.',
        500,
      );
    }

    try {
      $result = $this->fileUploadHandler->handleFileUpload(
        $this->createDrupalUploadedFile($upload),
        $item->getUploadValidators(),
        $directory,
        FileExists::Rename,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Document upload failed: @message', [
        '@message' => $e->getMessage(),
      ]);
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
      throw new ActionException(
        'invalid_request',
        implode(' ', $messages),
        400,
      );
    }

    return $result->getFile();
  }

  /**
   * Wraps a Symfony upload for Drupal's upload handler.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The source upload.
   *
   * @return \Drupal\file\Upload\UploadedFileInterface
   *   The Drupal upload adapter.
   */
  private function createDrupalUploadedFile(UploadedFile $upload): UploadedFileInterface {
    if (is_uploaded_file($upload->getPathname())) {
      return new FormUploadedFile($upload);
    }

    return new InputStreamUploadedFile(
      $upload->getClientOriginalName(),
      $upload->getClientOriginalName(),
      $upload->getPathname(),
      $upload->getSize(),
    );
  }

  /**
   * Creates the document media entity for a managed file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The managed file entity.
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The source upload.
   *
   * @return \Drupal\media\MediaInterface
   *   The saved media entity.
   */
  private function createMedia(FileInterface $file, UploadedFile $upload): MediaInterface {
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $this->getMediaBundle(),
      'name' => $upload->getClientOriginalName(),
      'status' => 0,
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

  /**
   * Checks whether a document is still attached to another session.
   *
   * @param int $document_id
   *   The media entity ID of the document.
   * @param int $session_id
   *   The session to exclude from the check.
   *
   * @return bool
   *   TRUE when another session references the document.
   */
  private function isReferencedByAnotherSession(int $document_id, int $session_id): bool {
    $ids = $this->entityTypeManager
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition($this->getSessionField() . '.target_id', $document_id)
      ->condition('id', $session_id, '<>')
      ->range(0, 1)
      ->execute();

    return $ids !== [];
  }

}
