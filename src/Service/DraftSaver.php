<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Saves a drafted node built from LLM-produced field values.
 *
 * Delegates validation and entity building to DraftAssembler (shared with
 * DraftingPlugin::preview() so the two paths cannot drift). Owns the
 * session-owned-node lifecycle: the first save creates the node and stores
 * it on the session; every later save adds a new revision to that same
 * node instead. A dangling node reference (the node was deleted) resolves
 * to no entity, so it falls back to the first-save path automatically.
 */
class DraftSaver implements DraftSaverInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly DraftAssemblerInterface $draftAssembler,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function save(AiEditorialSessionInterface $session, array $fields, ?string $templateId, int $version): array {
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

    // Save the node (atomic: parent's preSave chain saves any
    // inline children in the same transaction).
    $node->save();

    // Set node of editorial session.
    if ($existingNode === NULL) {
      $session->setNode((int) $node->id())->save();
    }

    return [
      'nodeId' => (string) $node->id(),
      'previewUrl' => $this->buildPreviewUrl($node),
    ];
  }

  /**
   * Builds the preview URL for a freshly saved node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The newly saved node.
   *
   * @return string
   *   Relative URL for the node preview.
   */
  private function buildPreviewUrl(NodeInterface $node): string {
    return $this->moderationInformation->isModeratedEntity($node)
      ? '/node/' . $node->id() . '/latest'
      : '/node/' . $node->id();
  }

}
