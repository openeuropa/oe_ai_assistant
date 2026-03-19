<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Reads the Vite manifest to resolve hashed asset filenames.
 *
 * The React app build produces a manifest.json that maps entry points
 * to their hashed output filenames. This service reads it so the
 * library definition can reference the correct JS file.
 */
class ManifestReader {

  /**
   * Cached manifest data.
   *
   * @var array<string, mixed>|null
   */
  private ?array $manifest = NULL;

  /**
   * Returns the JS filename from the Vite manifest.
   *
   * @return string|null
   *   The JS filename (relative to dist/), or NULL if not found.
   */
  public function getJsFile(): ?string {
    $manifest = $this->loadManifest();

    if ($manifest === NULL) {
      return NULL;
    }

    // The Vite manifest uses the entry source path as the key.
    // Our entry is src/init.tsx.
    $entry = $manifest['src/init.tsx'] ?? NULL;

    if ($entry && isset($entry['file'])) {
      return $entry['file'];
    }

    return NULL;
  }

  /**
   * Returns the path to the dist/ directory.
   *
   * @return string
   */
  public function getDistPath(): string {
    return dirname(__DIR__, 2) . '/dist';
  }

  /**
   * Loads and caches the Vite manifest.
   *
   * @return array<string, mixed>|null
   */
  private function loadManifest(): ?array {
    if ($this->manifest !== NULL) {
      return $this->manifest;
    }

    $manifestFile = $this->getDistPath() . '/.vite/manifest.json';

    if (!file_exists($manifestFile)) {
      return NULL;
    }

    $this->manifest = json_decode(file_get_contents($manifestFile), TRUE);

    return $this->manifest;
  }

}
