<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;

/**
 * Repository for the private publishable assets of a session.
 *
 * Publishable assets are private files (documents or images) an editor
 * uploads so they can later be placed into drafted content. They are stored
 * as unpublished media entities backed by private managed files, each
 * referencing its editorial session.
 */
final class PublishableAssetRepository extends DocumentRepositoryBase {

  /**
   * The API document category served by this repository.
   */
  public const string CATEGORY = 'publishable';

  /**
   * {@inheritdoc}
   */
  protected function getMediaBundle(): string {
    return 'ai_publishable_asset';
  }

  /**
   * {@inheritdoc}
   */
  protected function getSourceField(): string {
    return 'oe_ai_publishable_asset';
  }

  /**
   * {@inheritdoc}
   */
  protected function getCategory(): string {
    return self::CATEGORY;
  }

  /**
   * Returns the media IDs of the publishable assets owned by a session.
   *
   * The save-side attach guard and the publish-time promotion both need to
   * know which assets a session may legally use. The bundle filter inside
   * the query means a context document can never appear in this list, so the
   * "context documents are never attached" rule falls out of the ownership
   * check.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The owning session.
   *
   * @return int[]
   *   The media entity IDs of the session's publishable assets, in upload
   *   order. Empty when the session is new or owns none.
   */
  public function getSessionAssetIds(AiEditorialSessionInterface $session): array {
    return array_values(array_map(
      static fn (MediaInterface $media): int => (int) $media->id(),
      $this->loadAll($session),
    ));
  }

  /**
   * Loads a publishable asset only when it belongs to the session.
   *
   * Returns NULL instead of throwing, so callers that validate a set of
   * referenced IDs can decide how to report a rejection themselves.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The owning session.
   * @param string $assetId
   *   The media entity ID to resolve.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The asset media entity, or NULL when it does not belong to the session
   *   (wrong owner, wrong bundle, or missing).
   */
  public function loadSessionAsset(AiEditorialSessionInterface $session, string $assetId): ?MediaInterface {
    try {
      return $this->loadOwned($session, $assetId);
    }
    catch (ActionException) {
      return NULL;
    }
  }

  /**
   * Returns the managed file backing a publishable asset.
   *
   * @param \Drupal\media\MediaInterface $asset
   *   The publishable asset media entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The referenced file, or NULL when the source field is empty.
   */
  public function getAssetFile(MediaInterface $asset): ?FileInterface {
    return $this->getFile($asset);
  }

}
