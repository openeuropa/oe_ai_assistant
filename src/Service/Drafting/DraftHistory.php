<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Default draft history reader backed by the conversation transcript.
 */
class DraftHistory implements DraftHistoryInterface {

  /**
   * Constructs a DraftHistory.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the conversation message storage.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function countDrafts(EntityInterface $session): int {
    return count($this->collectResults($session));
  }

  /**
   * {@inheritdoc}
   */
  public function listDrafts(EntityInterface $session): array {
    $drafts = [];
    foreach ($this->collectResults($session) as $result) {
      $version = (int) $result['version'];
      $drafts[] = [
        'name' => sprintf('Draft %d', $version),
        'version' => $version,
        'context' => $result['context'],
      ];
    }
    return $drafts;
  }

  /**
   * {@inheritdoc}
   */
  public function getDraftContent(EntityInterface $session, int $version): ?array {
    foreach ($this->collectResults($session) as $result) {
      if ((int) $result['version'] !== $version) {
        continue;
      }
      return [
        'fields' => $result['fields'] ?? [],
        'templateId' => $result['context']['template']['id'] ?? NULL,
      ];
    }
    return NULL;
  }

  /**
   * Collects every stored draft_content result in transcript order.
   *
   * @param \Drupal\Core\Entity\EntityInterface $session
   *   The session hosting the conversation.
   *
   * @return array
   *   The result arrays, oldest first.
   */
  private function collectResults(EntityInterface $session): array {
    /** @var \Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    $results = [];
    foreach ($storage->loadTranscript($session) as $message) {
      foreach ($message->getToolCalls() as $call) {
        if (($call['function']['name'] ?? '') === 'draft_content'
          && isset($call['result'])
        ) {
          $results[] = $call['result'];
        }
      }
    }
    return $results;
  }

}
