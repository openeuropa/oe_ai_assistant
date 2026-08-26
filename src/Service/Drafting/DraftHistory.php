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
    foreach ($this->collectResults($session) as $position => $result) {
      // Versioned results carry their own version and snapshot; legacy
      // results are a flat fields map and get a positional version with no
      // provenance.
      $version = (int) ($result['version'] ?? $position + 1);
      $drafts[] = [
        'name' => sprintf('Draft %d', $version),
        'version' => $version,
        'context' => $result['context'] ?? NULL,
      ];
    }
    return $drafts;
  }

  /**
   * {@inheritdoc}
   */
  public function getDraftFields(EntityInterface $session, int $version): ?array {
    foreach ($this->collectResults($session) as $position => $result) {
      // The same versioning rule as listDrafts: legacy flat results get a
      // positional version and ARE the fields map themselves.
      $resultVersion = (int) ($result['version'] ?? $position + 1);
      if ($resultVersion !== $version) {
        continue;
      }
      $fields = $result['fields'] ?? $result;
      return is_array($fields) && $fields !== [] ? $fields : NULL;
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
