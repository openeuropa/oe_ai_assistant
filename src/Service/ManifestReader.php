<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Reads the Vite manifest to resolve hashed asset filenames.
 *
 * The React app build produces a .vite/manifest.json file inside the
 * dist/ directory. This manifest maps source entry point paths to their
 * content-hashed output filenames (e.g. 'assets/init-A1b2C3d4.js').
 * Hashed filenames enable long-lived HTTP caching: the filename changes
 * whenever the content changes, so browsers always load the latest build.
 *
 * This service is consumed by the module's library definition hook
 * (oe_ai_assistant_library_info_build()) to supply the correct JS filename
 * without hard-coding the hash in PHP code.
 *
 * The manifest is read from disk once and cached in memory for the
 * duration of the request. If the manifest file does not exist (e.g. the
 * React app has not been built yet) the service returns NULL gracefully
 * so callers can skip the library attachment rather than throwing an error.
 */
class ManifestReader {

  /**
   * Cached manifest data, populated lazily on first access.
   *
   * NULL indicates the manifest has not been loaded yet (not that it is
   * missing). Use loadManifest() rather than reading this property directly,
   * as loadManifest() handles both the not-yet-loaded and the missing cases.
   *
   * @var array<string, mixed>|null
   */
  private ?array $manifest = NULL;

  /**
   * Returns the JS filename from the Vite manifest.
   *
   * Looks up the manifest entry for the app's main entry point
   * (src/init.tsx) and returns the hashed output filename relative to the
   * dist/ directory. The returned value is suitable for use as a JS library
   * path in hook_library_info_build().
   *
   * @return string|null
   *   The hashed JS filename relative to dist/ (e.g. 'assets/init-A1b2.js'),
   *   or NULL when the manifest does not exist or lacks the expected entry.
   */
  public function getJsFile(): ?string {
    $manifest = $this->loadManifest();

    if ($manifest === NULL) {
      return NULL;
    }

    // The Vite manifest uses the source entry point path as the key.
    // Our app's entry is src/init.tsx; all other chunks are internal.
    $entry = $manifest['src/init.tsx'] ?? NULL;

    if ($entry && isset($entry['file'])) {
      return $entry['file'];
    }

    return NULL;
  }

  /**
   * Returns the absolute path to the dist/ directory.
   *
   * The dist/ directory sits two levels above this file in the module tree:
   * src/Service/ManifestReader.php -> src/ -> module root -> dist/
   *
   * @return string
   *   Absolute filesystem path to the dist directory, without trailing slash.
   */
  public function getDistPath(): string {
    // __DIR__ is src/Service; dirname twice gives the module root.
    return dirname(__DIR__, 2) . '/dist';
  }

  /**
   * Loads and caches the Vite manifest from disk.
   *
   * Reads dist/.vite/manifest.json and decodes it as an associative PHP
   * array. The result is stored in $this->manifest so subsequent calls
   * within the same request skip the file I/O.
   *
   * Returns NULL (and does not populate the cache) if the file does not
   * exist, allowing the caller to handle the "build not available" case.
   *
   * @return array<string, mixed>|null
   *   Decoded manifest data keyed by source entry path, or NULL when the
   *   manifest file is absent.
   */
  private function loadManifest(): ?array {
    // Return the in-memory cache if already populated.
    if ($this->manifest !== NULL) {
      return $this->manifest;
    }

    // Vite writes the manifest inside a .vite/ subdirectory of the output
    // directory (changed in Vite 5.x from the root of dist/).
    $manifestFile = $this->getDistPath() . '/.vite/manifest.json';

    if (!file_exists($manifestFile)) {
      // Do not cache NULL so that a subsequent call after the file is created
      // (e.g. a build run during the same long-running process) will retry.
      return NULL;
    }

    // Decode as associative arrays (TRUE flag); the manifest structure uses
    // plain string keys and does not require stdClass objects.
    $this->manifest = json_decode(file_get_contents($manifestFile), TRUE);

    return $this->manifest;
  }

}
