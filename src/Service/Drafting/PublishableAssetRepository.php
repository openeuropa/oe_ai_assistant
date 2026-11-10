<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

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

}
