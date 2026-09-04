<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiContentProvenanceStorageInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Default provenance recorder.
 */
class ProvenanceRecorder implements ProvenanceRecorderInterface {

  /**
   * The entity_version field name.
   */
  private const VERSION_FIELD = 'version';

  /**
   * Constructs a ProvenanceRecorder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The user performing the save.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountProxyInterface $currentUser,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(RevisionableInterface $entity, AiEditorialSessionInterface $session, AiConversationMessageInterface $message): ?AiContentProvenanceInterface {
    try {
      $storage = $this->provenanceStorage();
      $existing = $storage->loadForRevision(
        $entity->getEntityTypeId(), (int) $entity->id(), (int) $entity->getRevisionId()
      );
      if ($existing !== NULL) {
        return $existing;
      }

      $tokens = $this->sumTokenUsage($session, $message);
      $version = $this->snapshotVersion($entity);

      $record = $storage->create([
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id' => (int) $entity->id(),
        'revision_id' => (int) $entity->getRevisionId(),
        'uid' => (int) $this->currentUser->id(),
        'session' => $session->id(),
        'message' => $message->id(),
        'template' => $session->get('template')->target_id ?: NULL,
        'tokens_input' => $tokens['input'],
        'tokens_output' => $tokens['output'],
        'tokens_total' => $tokens['total'],
        'provider' => (string) $message->get('provider')->value,
        'model' => (string) $message->get('model')->value,
        'version_major' => $version['major'],
        'version_minor' => $version['minor'],
        'version_patch' => $version['patch'],
      ]);
      $record->save();
      return $record;
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to record AI provenance for @type @id revision @vid: @e', [
        '@type' => $entity->getEntityTypeId(),
        '@id' => $entity->id(),
        '@vid' => $entity->getRevisionId(),
        '@e' => (string) $e,
      ]);
      return NULL;
    }
  }

  /**
   * Sums token usage over the drafting turn and its sub-agent tree.
   *
   * @return array<string, int>
   *   Keys input, output and total.
   */
  private function sumTokenUsage(AiEditorialSessionInterface $session, AiConversationMessageInterface $message): array {
    $totals = ['input' => 0, 'output' => 0, 'total' => 0];
    foreach ($this->messageStorage()->loadTree($session) as $branch) {
      if ((int) $branch['message']->id() === (int) $message->id()) {
        $this->sumBranch($branch, $totals);
        break;
      }
    }
    return $totals;
  }

  /**
   * Adds one branch's token usage, recursively, to the running totals.
   *
   * @param array $branch
   *   A branch as returned by loadTree(): a message and its children.
   * @param array<string, int> $totals
   *   The running totals, updated in place.
   */
  private function sumBranch(array $branch, array &$totals): void {
    $usage = $branch['message']->getTokenUsage();
    foreach (array_keys($totals) as $key) {
      $totals[$key] += (int) ($usage[$key] ?? 0);
    }
    foreach ($branch['children'] as $child) {
      $this->sumBranch($child, $totals);
    }
  }

  /**
   * Snapshots the entity_version value of a revision.
   *
   * @return array<string, int|null>
   *   Keys major, minor and patch.
   */
  private function snapshotVersion(RevisionableInterface $entity): array {
    $empty = ['major' => NULL, 'minor' => NULL, 'patch' => NULL];
    if (!$entity instanceof FieldableEntityInterface
      || !$entity->hasField(self::VERSION_FIELD)
      || $entity->get(self::VERSION_FIELD)->isEmpty()) {
      return $empty;
    }
    $item = $entity->get(self::VERSION_FIELD)->first();
    return [
      'major' => (int) $item->get('major')->getValue(),
      'minor' => (int) $item->get('minor')->getValue(),
      'patch' => (int) $item->get('patch')->getValue(),
    ];
  }

  /**
   * Returns the provenance storage handler.
   */
  private function provenanceStorage(): AiContentProvenanceStorageInterface {
    $storage = $this->entityTypeManager->getStorage('ai_content_provenance');
    assert($storage instanceof AiContentProvenanceStorageInterface);
    return $storage;
  }

  /**
   * Returns the conversation message storage handler.
   */
  private function messageStorage(): AiConversationMessageStorageInterface {
    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    assert($storage instanceof AiConversationMessageStorageInterface);
    return $storage;
  }

}
