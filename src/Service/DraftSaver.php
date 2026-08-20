<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Saves a drafted node built from server-resolved LLM field values.
 *
 * The session owns a single node: the first save creates it and later saves
 * create revisions. Provenance is linked to the exact transcript message that
 * stored the version being saved.
 */
class DraftSaver implements DraftSaverInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly DraftAssemblerInterface $draftAssembler,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function save(AiEditorialSessionInterface $session, array $fields, ?string $templateId, int $version, AiConversationMessageInterface $message): array {
    $existingNode = $session->getNode();

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->draftAssembler->assemble($session->getContentType(), $fields, $templateId, $existingNode);

    if ($existingNode === NULL) {
      $node->setOwnerId((int) $this->currentUser->id());
    }
    else {
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage(sprintf('Draft %d from session %s', $version, $session->label()));
      $node->setRevisionUserId((int) $this->currentUser->id());
      $node->setRevisionCreationTime($this->time->getRequestTime());
    }

    if ($this->moderationInformation->isModeratedEntity($node)) {
      $node->set('moderation_state', 'draft');
    }
    else {
      $node->setPublished(FALSE);
    }

    $node->save();

    if ($existingNode === NULL) {
      $session->setNode((int) $node->id())->save();
    }

    $this->createProvenance($node, $session, $message);

    return [
      'nodeId' => (string) $node->id(),
      'previewUrl' => $this->buildPreviewUrl($node),
    ];
  }

  /**
   * Records provenance for the newly saved revision.
   */
  private function createProvenance(NodeInterface $node, AiEditorialSessionInterface $session, AiConversationMessageInterface $message): void {
    $tokens = $this->aggregateTokenUsage($message);
    $version = $this->snapshotVersion($node);

    $this->entityTypeManager->getStorage('ai_content_provenance')->create([
      'entity_type' => $node->getEntityTypeId(),
      'entity_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
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
    ])->save();
  }

  /**
   * Aggregates token usage for the triggering message and its descendants.
   *
   * @return array<string, int>
   *   The input, output, and total token counts.
   */
  private function aggregateTokenUsage(AiConversationMessageInterface $message): array {
    $usage = $message->getTokenUsage();
    $totals = [
      'input' => (int) ($usage['input'] ?? 0),
      'output' => (int) ($usage['output'] ?? 0),
      'total' => (int) ($usage['total'] ?? 0),
    ];

    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('parent', (int) $message->id())
      ->sort('created')
      ->sort('id')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $child) {
      $nested = $this->aggregateTokenUsage($child);
      $totals['input'] += $nested['input'];
      $totals['output'] += $nested['output'];
      $totals['total'] += $nested['total'];
    }

    return $totals;
  }

  /**
   * Snapshots the saved node's entity-version values.
   *
   * @return array<string, int>
   *   The major, minor, and patch values.
   */
  private function snapshotVersion(NodeInterface $node): array {
    return [
      'major' => (int) $node->get('version')->first()->get('major')->getValue(),
      'minor' => (int) $node->get('version')->first()->get('minor')->getValue(),
      'patch' => (int) $node->get('version')->first()->get('patch')->getValue(),
    ];
  }

  /**
   * Builds the preview URL for a freshly saved node.
   */
  private function buildPreviewUrl(NodeInterface $node): string {
    return $this->moderationInformation->isModeratedEntity($node)
      ? '/node/' . $node->id() . '/latest'
      : '/node/' . $node->id();
  }

}
