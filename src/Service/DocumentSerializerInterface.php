<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\media\MediaInterface;

/**
 * Defines a serializer for assistant document media entities.
 */
interface DocumentSerializerInterface {

  /**
   * Serializes a document media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media entity.
   * @param string $sourceField
   *   The media source field name.
   *
   * @return array{id: string, title: string, meta: array{type: string, size: int}}
   *   The serialized document.
   */
  public function serialize(MediaInterface $media, string $sourceField): array;

  /**
   * Serializes a list of document media entities.
   *
   * @param array<int, mixed> $media
   *   The document media entities.
   * @param string $sourceField
   *   The media source field name.
   *
   * @return array<int, array{id: string, title: string, meta: array{type: string, size: int}}>
   *   The serialized documents.
   */
  public function serializeList(array $media, string $sourceField): array;

}
