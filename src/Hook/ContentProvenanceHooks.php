<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Contains hooks for AI content provenance cleanup.
 */
final class ContentProvenanceHooks {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_ai_editorial_session_delete().
   */
  #[Hook('ai_editorial_session_delete')]
  public function deleteSessionProvenance(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);

    $provenance_storage = $this->entityTypeManager->getStorage('ai_content_provenance');
    $ids = $provenance_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session.target_id', (int) $entity->id())
      ->execute();
    if ($ids) {
      foreach ($provenance_storage->loadMultiple($ids) as $provenance) {
        $provenance->set('session', NULL);
        $provenance->set('message', NULL);
        $provenance->save();
      }
    }

    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    $storage->deleteForHost($entity);
  }

  /**
   * Implements hook_ai_conversation_message_delete().
   */
  #[Hook('ai_conversation_message_delete')]
  public function deleteMessageProvenance(EntityInterface $entity): void {
    assert($entity instanceof AiConversationMessageInterface);

    $provenance_storage = $this->entityTypeManager->getStorage('ai_content_provenance');
    $ids = $provenance_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('message.target_id', (int) $entity->id())
      ->execute();
    if ($ids) {
      foreach ($provenance_storage->loadMultiple($ids) as $provenance) {
        $provenance->set('message', NULL);
        $provenance->save();
      }
    }
  }

}
