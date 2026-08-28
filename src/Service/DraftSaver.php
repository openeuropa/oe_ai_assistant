<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Saves a drafted node built from LLM-produced field values.
 *
 * Delegates validation and entity building to DraftAssembler (shared with
 * DraftingPlugin::preview() so the two paths cannot drift), then applies
 * owner + moderation state and saves.
 */
class DraftSaver implements DraftSaverInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly DraftAssemblerInterface $draftAssembler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function save(string $bundle, array $fields, ?string $templateId = NULL): array {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->draftAssembler->assemble($bundle, $fields, $templateId);

    // Set owner and moderation state.
    $node->setOwnerId((int) $this->currentUser->id());
    if ($this->moderationInformation->isModeratedEntity($node)) {
      $node->set('moderation_state', 'draft');
    }
    else {
      $node->setPublished(FALSE);
    }

    // Save the node (atomic: parent's preSave chain saves any
    // inline children in the same transaction).
    $node->save();

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
