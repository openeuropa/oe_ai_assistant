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
  /** Editorial session ID the app is mounted on. */
  sessionId: string;
  /** CMS content node ID the editor is currently working on. */
  nodeId: string | null;
  /** Authenticated user ID from the CMS session. */
  userId: string;
  /** List of plugin IDs that the host page wants enabled. */
  enabledPlugins: string[];
  /** Per-plugin init configuration from the host page. */
  pluginConfig: Record<string, Record<string, unknown>>;
  /** Human-readable session title shown in the session header. */
  sessionTitle: string;
  /** URL the exit control navigates to. Empty string hides the control. */
  exitUrl: string;
  /** Display name of the authenticated user (e.g. for avatars). */
  userName: string;
  /** Disclaimer shown under the composer. Empty string hides it. */
  disclaimer: string;
}

/** Init-time config accepted from the host application. */
export interface AppInitConfig
  extends Omit<Partial<AppConfig>, "userId" | "sessionId"> {
  /** Authenticated user ID from the CMS session. Required. */
  userId: string;
  /** Editorial session ID the app is mounted on. Required. */
  sessionId: string;
}

/** Sensible defaults for options that the host may omit. */
const defaults = {
  apiBaseUrl: "/api",
  nodeId: null,
  enabledPlugins: [],
  pluginConfig: {},
  sessionTitle: "",
  exitUrl: "",
  userName: "",
  disclaimer: "",
} satisfies Omit<AppConfig, "userId" | "sessionId">;

/** Module-level singleton holding the active config after init(). */
let activeConfig: AppConfig | null = null;

/**
 * Set the application config. Called once during init(), before the
 * React tree mounts. Merges the provided partial over the defaults.
 */
export function setConfig(config: AppInitConfig): void {
  const userId = config.userId.trim();
  const sessionId = config.sessionId.trim();

  if (!userId) {
    throw new Error(
      "[ai-editorial-assistant] init() requires a non-empty userId",
    );
  }

  if (!sessionId) {
    throw new Error(
      "[ai-editorial-assistant] init() requires a non-empty sessionId",
    );
  }

  activeConfig = { ...defaults, ...config, userId, sessionId };
}

/**
 * Read the current application config. Safe to call from anywhere
 * after init() has been called (components, hooks, API client, etc.).
 */
export function getConfig(): AppConfig {
  if (!activeConfig) {
    throw new Error(
      "[ai-editorial-assistant] App config accessed before init() completed",
    );
  }

  return activeConfig;
}
