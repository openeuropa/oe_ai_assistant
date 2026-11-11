<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Service\Drafting\PublishableAssetPromoterInterface;

/**
 * Promotes a drafted node's attached assets when the node is published.
 *
 * Fires on the transition to published only: a node created already
 * published, or one whose save flips it from unpublished to published. Draft
 * saves (which keep the node unpublished or in a draft moderation state) do
 * nothing. Both moderated and non-moderated content types are covered,
 * because content moderation keeps the node's published flag in sync with the
 * current state, so the published flag is the single signal for both.
 */
final class PublishableAssetPromotionHooks {

  use AutowireTrait;

  public function __construct(
    private readonly PublishableAssetPromoterInterface $promoter,
  ) {}

  /**
   * Implements hook_node_insert().
   *
   * Covers the edge case of a node inserted already published AND already
   * linked to its session. The normal drafting flow never hits this: the
   * first save creates an unpublished draft and only then writes the node
   * reference onto the session, so promotion arrives through node_update.
   */
  #[Hook('node_insert')]
  public function promoteOnInsert(NodeInterface $node): void {
    if ($node->isPublished()) {
      $this->promoter->promoteForNode($node);
    }
  }

  /**
   * Implements hook_node_update().
   *
   * Promotes only when this save is the transition to published; a save that
   * leaves an already-published node published (an edit, a new published
   * revision) does not re-promote, and an unpublished save does nothing.
   */
  #[Hook('node_update')]
  public function promoteOnUpdate(NodeInterface $node): void {
    $original = $node->getOriginal();
    $wasPublished = $original instanceof NodeInterface && $original->isPublished();
    if ($node->isPublished() && !$wasPublished) {
      $this->promoter->promoteForNode($node);
    }
  }

}
