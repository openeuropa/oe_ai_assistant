/**
 * Runtime configuration store.
 *
 * Holds the application config set once during init() and read
 * throughout the app's lifetime. The config is passed explicitly by
 * the host page (Drupal or dev entry point) -- the app never scrapes
 * the DOM or reads globals on its own.
 *
 * Call setConfig() exactly once (from init.ts) before anything renders.
 * Call getConfig() anywhere to read the current config.
 */

/** Shape of the runtime configuration the app needs to operate. */
export interface AppConfig {
  /** Base URL for all API requests (e.g. "/api" or "https://cms.example.com/api"). */
  apiBaseUrl: string;
  /** CMS content node ID the editor is currently working on. */
  nodeId: string | null;
  /** Authenticated user ID from the CMS session. */
  userId: string | null;
  /** List of plugin IDs that the host page wants enabled. */
  enabledPlugins: string[];
  /** Per-plugin init configuration from the host page. */
  pluginConfig: Record<string, Record<string, unknown>>;
}

/** Sensible defaults for standalone development (no host page). */
const defaults: AppConfig = {
  apiBaseUrl: "/api",
  nodeId: null,
  userId: null,
  enabledPlugins: [],
  pluginConfig: {},
};

/** Module-level singleton holding the active config. */
let activeConfig: AppConfig = { ...defaults };

/**
 * Set the application config. Called once during init(), before the
 * React tree mounts. Merges the provided partial over the defaults.
 */
export function setConfig(partial: Partial<AppConfig>): void {
  activeConfig = { ...defaults, ...partial };
}

/**
 * Read the current application config. Safe to call from anywhere
 * after init() has been called (components, hooks, API client, etc.).
 */
export function getConfig(): AppConfig {
  return activeConfig;
}
