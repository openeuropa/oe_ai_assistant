<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\Drafting\PublishableAssetRepository;

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
    private readonly PublishableAssetRepository $publishableAssetRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function save(AiEditorialSessionInterface $session, array $fields, ?string $templateId, int $version): array {
    $existingNode = $session->getNode();

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->draftAssembler->assemble($session->getContentType(), $fields, $templateId, $existingNode);

    // Only the publishable assets referenced by this session may be attached
    // to the draft. Any other media id (another session's asset, a context
    // document, or a non-existent id) is rejected before the node is saved.
    $this->guardAttachedAssets($session, $node, array_keys($fields));

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
   * Rejects media references the session is not allowed to attach.
   *
   * The LLM places an asset by writing a media id into one of the content
   * type's reference fields; that id arrives as an ordinary field value on
   * the built node. Only the publishable assets referenced by this session
   * are attachable: a media id from another session, a context document, or
   * a non-existent id must fail the save loudly rather than be persisted.
   *
   * Only the fields this draft actually wrote are checked. Media placed by a
   * host-controlled template default is trusted, and media already stored on
   * a prior revision of an updated node is left untouched.
   *
   * The ownership check runs against the publishable-asset repository, whose
   * bundle filter (ai_publishable_asset) means a context document can never
   * be accepted - the "context documents are never attached" rule needs no
   * separate branch here.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the draft.
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The built, unsaved node whose written fields are inspected.
   * @param string[] $writtenFieldNames
   *   The machine names of the fields this draft submitted.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When a written media reference is not owned by the session (400).
   */
  private function guardAttachedAssets(AiEditorialSessionInterface $session, ContentEntityInterface $node, array $writtenFieldNames): void {
    $allowedIds = $this->publishableAssetRepository->getSessionAssetIds($session);

    foreach ($writtenFieldNames as $fieldName) {
      if (!is_string($fieldName) || !$node->hasField($fieldName)) {
        continue;
      }
      $items = $node->get($fieldName);
      // Only media-targeting entity reference fields can carry an asset; any
      // other field (scalar, paragraph, taxonomy, user) is irrelevant here.
      if (!$items instanceof EntityReferenceFieldItemListInterface
        || $items->getFieldDefinition()->getFieldStorageDefinition()->getSetting('target_type') !== 'media'
      ) {
        continue;
      }

      foreach ($items as $item) {
        $targetId = $item->target_id;
        // An unsaved inline media entity (no target_id yet) is not an asset
        // reference and is skipped; only resolvable ids are validated.
        if ($targetId === NULL || $targetId === '') {
          continue;
        }
        if (!in_array((int) $targetId, $allowedIds, TRUE)) {
          throw new ActionException(
            'invalid_request',
            sprintf(
              'Media "%s" referenced by field "%s" is not a publishable asset of this editorial session.',
              (string) $targetId,
              $fieldName,
            ),
            400,
          );
        }
      }
    }
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
