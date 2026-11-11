<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\node\NodeInterface;

/**
 * Promotes a node's attached publishable-asset files to the public scheme.
 *
 * Publishable assets are stored privately while a draft is in progress. When
 * the node they were placed into is published, the files they back must be
 * served on the published site, so they move from the private filesystem to
 * the public one. Context documents are never promoted; only the publishable
 * assets the node actually references are.
 */
interface PublishableAssetPromoterInterface {

  /**
   * Promotes the publishable-asset files a published node references.
   *
   * Resolves the editorial session that produced the node, intersects the
   * node's media references with that session's publishable assets, moves
   * each attached asset's file to the public scheme, and detaches the asset
   * from the session so a later session deletion no longer reaps the file now
   * backing published content. A no-op when the node has no session or no
   * attached asset.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node that has just become published.
   */
  public function promoteForNode(NodeInterface $node): void;

}
