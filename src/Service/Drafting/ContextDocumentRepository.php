<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

/**
 * Repository for the private drafting context documents of a session.
 *
 * Context documents are private reference files an editor uploads to
 * ground the drafting conversation. They are stored as unpublished media
 * entities backed by private managed files and referenced from the
 * editorial session.
 */
final class ContextDocumentRepository extends DocumentRepositoryBase {

  /**
   * The API document category served by this repository.
   */
  public const string CATEGORY = 'context';

  /**
   * {@inheritdoc}
   */
  protected function getSessionField(): string {
    return 'context_documents';
  }

  /**
   * {@inheritdoc}
   */
  protected function getMediaBundle(): string {
    return 'ai_context_document';
  }

  /**
   * {@inheritdoc}
   */
  protected function getSourceField(): string {
    return 'oe_ai_context_document';
  }

}
