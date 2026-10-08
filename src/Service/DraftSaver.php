<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
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
    private readonly AccountProxyInterface $currentUser,
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly DraftAssemblerInterface $draftAssembler,
    private readonly TimeInterface $time,
    private readonly ProvenanceRecorderInterface $provenanceRecorder,
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

    $this->provenanceRecorder->record($node, $session, $message);

    return [
      'nodeId' => (string) $node->id(),
      'previewUrl' => $this->buildPreviewUrl($node),
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
