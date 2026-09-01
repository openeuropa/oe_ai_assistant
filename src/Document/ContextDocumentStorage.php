<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Document;

/**
 * Defines storage details for private drafting context documents.
 */
final class ContextDocumentStorage {

  /**
   * The document category used for private drafting context files.
   */
  public const string CATEGORY = 'context';

  /**
   * The session field that references private context documents.
   */
  public const string SESSION_FIELD = 'context_documents';

  /**
   * The media bundle used for private context documents.
   */
  public const string MEDIA_BUNDLE = 'ai_context_document';

  /**
   * The media source field that stores the uploaded context file.
   */
  public const string SOURCE_FIELD = 'field_media_context_document';

  /**
   * The media field that stores the extracted document summary.
   */
  public const string SUMMARY_FIELD = 'field_document_summary';

  /**
   * The private directory used for uploaded context documents.
   */
  public const string UPLOAD_DIRECTORY = 'private://ai-context-documents';

  /**
   * Returns working-material media storage details keyed by media bundle.
   *
   * @return array<string, array{category: string, sessionField: string, mediaBundle: string, sourceField: string, summaryField: string}>
   *   The supported document storage details.
   */
  public static function workingMaterialBundles(): array {
    return [
      self::MEDIA_BUNDLE => [
        'category' => self::CATEGORY,
        'sessionField' => self::SESSION_FIELD,
        'mediaBundle' => self::MEDIA_BUNDLE,
        'sourceField' => self::SOURCE_FIELD,
        'summaryField' => self::SUMMARY_FIELD,
      ],
    ];
  }

  /**
   * Returns working-material media storage details for a bundle.
   *
   * @return array{category: string, sessionField: string, mediaBundle: string, sourceField: string, summaryField: string}|null
   *   The supported document storage details, or NULL for unsupported bundles.
   */
  public static function workingMaterialBundle(string $bundle): ?array {
    $bundles = self::workingMaterialBundles();
    return $bundles[$bundle] ?? NULL;
  }

  /**
   * Returns working-material media storage details for a category.
   *
   * @return array{category: string, sessionField: string, mediaBundle: string, sourceField: string, summaryField: string}|null
   *   The supported document storage details, or NULL for unsupported
   *   categories.
   */
  public static function workingMaterialCategory(string $category): ?array {
    foreach (self::workingMaterialBundles() as $details) {
      if ($details['category'] === $category) {
        return $details;
      }
    }

    return NULL;
  }

  /**
   * This class only carries shared storage constants.
   */
  private function __construct() {}

}
