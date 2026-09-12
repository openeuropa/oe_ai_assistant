<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\file\Upload\UploadedFileInterface;

/**
 * Manages the documents of one category attached to editorial sessions.
 *
 * A repository owns every storage detail of its document category (media
 * bundle, source field, upload directory) and is the only component allowed
 * to know them. A document references the session it belongs to. Callers
 * deal exclusively in sessions, uploads and serialized document items.
 */
interface DocumentRepositoryInterface {

  /**
   * Stores an uploaded file and attaches it to a session as a document.
   *
   * Saves the upload as a managed file and wraps it in a media entity that
   * references the session. If any step fails, the entities created so far
   * are deleted before the failure is rethrown.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session receiving the document.
   * @param \Drupal\file\Upload\UploadedFileInterface $upload
   *   The uploaded file, ready for the file upload handler.
   *
   * @return array<string, string|array<string, string|int>>
   *   The serialized document item.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the upload cannot be stored or fails validation.
   */
  public function add(AiEditorialSessionInterface $session, UploadedFileInterface $upload): array;

  /**
   * Lists the documents of a session in upload order.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session whose documents should be listed.
   *
   * @return array<int, array<string, string|array<string, string|int>>>
   *   The serialized document items.
   */
  public function list(AiEditorialSessionInterface $session): array;

  /**
   * Deletes a document of the session together with its file.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the document belongs to.
   * @param string $documentId
   *   The media entity ID of the document to remove.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the document does not belong to the session.
   */
  public function remove(AiEditorialSessionInterface $session, string $documentId): void;

  /**
   * Runs the extraction pipeline on a document of the session.
   *
   * A no-op that reports the state when the document is in flight or done.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the document belongs to.
   * @param string $documentId
   *   The media entity ID of the document.
   *
   * @return string
   *   The document state after the call.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the document does not belong to the session.
   */
  public function extract(AiEditorialSessionInterface $session, string $documentId): string;

  /**
   * Deletes every document of a session, with their managed files.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session being deleted.
   */
  public function deleteForSession(AiEditorialSessionInterface $session): void;

}
