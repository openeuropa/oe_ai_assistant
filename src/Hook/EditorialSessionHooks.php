<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Document\ContextDocumentStorage;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;

/**
 * Contains hooks regarding editorial session entities.
 */
final class EditorialSessionHooks {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessageRecorderInterface $messageRecorder,
  ) {}

  /**
   * Implements hook_ai_editorial_session_insert() for content creation sessions.
   *
   * Records the initial editorial state as the first transcript row so the
   * timeline documents what the session started with. The event is recorded
   * only for the content creation bundle; other bundles define their own
   * timeline behavior.
   */
  #[Hook('ai_editorial_session_insert')]
  public function recordContentCreationInitialState(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);

    if ($entity->bundle() !== 'content_creation') {
      return;
    }

    $tone = $entity->get('tone')->entity;
    $template = $entity->get('template')->entity;
    $to = [
      'tone' => $tone
        ? ['id' => (string) $tone->id(), 'label' => (string) $tone->label()]
        : NULL,
      'template' => $template
        ? ['id' => (string) $template->id(), 'label' => (string) $template->label()]
        : NULL,
      'documents' => [],
    ];
    $parts = [];
    if ($to['tone']) {
      $parts[] = 'tone ' . $to['tone']['label'];
    }
    if ($to['template']) {
      $parts[] = 'template ' . $to['template']['label'];
    }
    $summary = $parts === []
      ? 'Session started'
      : 'Session started with ' . implode(' and ', $parts);
    $this->messageRecorder->recordEvent(
      $entity, $summary,
      ['type' => 'session_start', 'from' => NULL, 'to' => $to],
      (int) $entity->getOwnerId(),
    );
  }

  /**
   * Implements hook_ai_editorial_session_delete().
   *
   * Removes the conversation hosted by the deleted session.
   */
  #[Hook('ai_editorial_session_delete')]
  public function deleteSessionMessages(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);

    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    assert($storage instanceof AiConversationMessageStorageInterface);

    $storage->deleteForHost($entity);
  }

  /**
   * Implements hook_ai_editorial_session_delete().
   *
   * Removes private context document media that are owned only by the deleted
   * session, together with their managed files.
   */
  #[Hook('ai_editorial_session_delete')]
  public function deleteSessionContextDocuments(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);

    if (!$entity->hasField(ContextDocumentStorage::SESSION_FIELD)) {
      return;
    }

    $document_ids = [];
    foreach ($entity->get(ContextDocumentStorage::SESSION_FIELD) as $item) {
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
        || $media->bundle() !== ContextDocumentStorage::MEDIA_BUNDLE
        || $this->documentIsReferencedByAnotherSession((int) $media->id(), (int) $entity->id())
      ) {
        continue;
      }

      $file = $media->get(ContextDocumentStorage::SOURCE_FIELD)->entity;
      $media->delete();
      if ($file instanceof FileInterface) {
        $file->delete();
      }
    }
  }

  /**
   * Checks whether a context document is still attached to another session.
   */
  private function documentIsReferencedByAnotherSession(int $document_id, int $deleted_session_id): bool {
    $ids = $this->entityTypeManager
      ->getStorage('ai_editorial_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition(ContextDocumentStorage::SESSION_FIELD . '.target_id', $document_id)
      ->condition('id', $deleted_session_id, '<>')
      ->range(0, 1)
      ->execute();

    return $ids !== [];
  }

}
