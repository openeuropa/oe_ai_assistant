<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
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

}
