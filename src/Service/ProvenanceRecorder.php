<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityStorageException;
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
  public function recordDraft(AiEditorialSessionInterface $session, AiConversationMessageInterface $message): ?AiContentProvenanceInterface {
    try {
      $storage = $this->provenanceStorage();
      $record = $storage->loadPendingForMessage((int) $message->id())
        ?? $storage->create();
      $this->applyDraftSnapshot($record, $session, $message);
      $record->save();
      return $record;
    }
    catch (EntityStorageException $e) {
      $this->logger->error('Failed to record AI provenance for draft message @message: @e', [
        '@message' => $message->id(),
        '@e' => (string) $e,
      ]);
      return NULL;
    }
  }

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

      $record = $storage->loadPendingForMessage((int) $message->id())
        ?? $storage->create();
      $this->applyDraftSnapshot($record, $session, $message);
      $version = $this->snapshotVersion($entity);
      $record->set('uid', (int) $this->currentUser->id());
      $record->set('entity_type', $entity->getEntityTypeId());
      $record->set('entity_id', (int) $entity->id());
      $record->set('revision_id', (int) $entity->getRevisionId());
      $record->set('version_major', $version['major']);
      $record->set('version_minor', $version['minor']);
      $record->set('version_patch', $version['patch']);
      $record->save();
      return $record;
    }
    catch (EntityStorageException $e) {
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
   * Applies the generation-time provenance snapshot to a record.
   */
  private function applyDraftSnapshot(AiContentProvenanceInterface $record, AiEditorialSessionInterface $session, AiConversationMessageInterface $message): void {
    $tokens = $this->sumTokenUsage($session, $message);
    $template = $message->getDraftTemplateId()
      ?? ($session->get('template')->target_id ?: NULL);

    $record->set('uid', (int) $this->currentUser->id());
    $record->set('session', $session->id());
    $record->set('message', $message->id());
    $record->set('template', $template);
    $record->set('tokens_input', $tokens['input']);
    $record->set('tokens_output', $tokens['output']);
    $record->set('tokens_total', $tokens['total']);
    $record->set('provider', (string) $message->get('provider')->value);
    $record->set('model', (string) $message->get('model')->value);
  }

  /**
   * Sums token usage since the previous draft through the drafting turn.
   *
   * For the first draft, this includes the conversation from the start of the
   * session. For later drafts, the previous draft and its sub-agent branch are
   * excluded. The current drafting turn is included recursively, while turns
   * made after it are excluded.
   *
   * @return array<string, int>
   *   Keys input, output and total.
   */
  private function sumTokenUsage(AiEditorialSessionInterface $session, AiConversationMessageInterface $message): array {
    $totals = ['input' => 0, 'output' => 0, 'total' => 0];
    foreach ($this->messageStorage()->loadTree($session) as $branch) {
      $branch_message = $branch['message'];
      if ((int) $branch_message->id() !== (int) $message->id()
        && $this->isDraftTurn($branch_message)
      ) {
        $totals = ['input' => 0, 'output' => 0, 'total' => 0];
        continue;
      }
      $this->sumBranch($branch, $totals);
      if ((int) $branch_message->id() === (int) $message->id()) {
        break;
      }
    }
    return $totals;
  }

  /**
   * Returns whether a conversation message triggered draft creation.
   */
  private function isDraftTurn(AiConversationMessageInterface $message): bool {
    foreach ($message->getToolCalls() as $tool_call) {
      if (($tool_call['function']['name'] ?? NULL) === 'draft_content') {
        return TRUE;
      }
    }
    return FALSE;
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
    $input = (int) ($usage['input'] ?? 0);
    $output = (int) ($usage['output'] ?? 0);
    $totals['input'] += $input;
    $totals['output'] += $output;
    $totals['total'] += $usage['total'] === NULL
      ? $input + $output
      : (int) $usage['total'];
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
