<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Manages the documents of one category attached to editorial sessions.
 *
 * A repository owns every storage detail of its document category (media
 * bundle, source field, session reference field, upload directory) and is
 * the only component allowed to know them. Callers deal exclusively in
 * sessions, uploads and serialized document items.
 */
interface DocumentRepositoryInterface {

  /**
   * Stores an uploaded file and attaches it to a session as a document.
   *
   * Saves the upload as a managed file, wraps it in a media entity and
   * appends a reference to the session. If any step fails, the entities
   * created so far are deleted before the failure is rethrown.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session receiving the document.
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The valid uploaded file.
   *
   * @return array<string, string|array<string, string|int>>
   *   The serialized document item.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the upload cannot be stored or fails validation.
   */
  public function add(AiEditorialSessionInterface $session, UploadedFile $upload): array;

  /**
   * Lists the documents referenced by a session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session whose documents should be listed.
   *
   * @return array<int, array<string, string|array<string, string|int>>>
   *   The serialized document items.
   */
  public function list(AiEditorialSessionInterface $session): array;

  /**
   * Detaches a document from a session and deletes its entities.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session that references the document.
   * @param string $documentId
   *   The media entity ID of the document to remove.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the document is not referenced by the session.
   */
  public function remove(AiEditorialSessionInterface $session, string $documentId): void;

  /**
   * Deletes the documents orphaned by a session deletion.
   *
   * Removes the media entities and managed files of documents referenced
   * by the given session, unless another session still references them.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session being deleted.
   */
  public function deleteOrphanedBy(AiEditorialSessionInterface $session): void;

}
