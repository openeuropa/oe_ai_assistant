<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Serializes assistant document media entities for API and UI bootstrap.
 */
class DocumentSerializer implements DocumentSerializerInterface {

  /**
   * {@inheritdoc}
   */
  public function serialize(MediaInterface $media, string $sourceField): array {
    $file = $this->getDocumentFile($media, $sourceField);
    $filename = $file?->getFilename() ?: $media->label();
    $extension = pathinfo($filename, PATHINFO_EXTENSION);

    return [
      'id' => (string) $media->id(),
      'title' => (string) ($media->label() ?: $filename),
      'meta' => [
        'type' => $extension !== '' ? strtolower($extension) : 'file',
        'size' => $file instanceof FileInterface ? (string) ByteSizeMarkup::create((int) $file->getSize()) : '',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function serializeList(array $media, string $sourceField): array {
    $documents = [];
    foreach ($media as $item) {
      if ($item instanceof MediaInterface) {
        $documents[] = $this->serialize($item, $sourceField);
      }
    }

    return $documents;
  }

  /**
   * Gets the file referenced by a document media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media entity.
   * @param string $sourceField
   *   The media source field name.
   *
   * @return \Drupal\file\FileInterface|null
   *   The referenced file, if available.
   */
  private function getDocumentFile(MediaInterface $media, string $sourceField): ?FileInterface {
    if (!$media->hasField($sourceField)) {
      return NULL;
    }

    $file = $media->get($sourceField)->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

}
