/**
 * Public initialisation API.
 *
 * This is the single entry point the host page (Drupal, standalone dev,
 * or any other consumer) calls to mount the AI Editorial Assistant. It
 * accepts a DOM target and configuration, wires up everything internally,
 * and returns a handle to unmount later if needed.
 *
 * Usage from a Drupal module:
 *
 *   <div id="ai-assistant"></div>
 *   <script>
 *     AiEditorialAssistant.init("#ai-assistant", {
 *       apiBaseUrl: "/api",
 *       nodeId: "123",
 *       userId: "editor-7",
 *     });
 *   </script>
 *
 * Usage in dev (main.tsx calls this with defaults).
 */

import "./index.css";
import { StrictMode } from "react";
import type { Root } from "react-dom/client";
import { createRoot } from "react-dom/client";
import { App } from "./app";
import type { AppConfig } from "./config";
import { setConfig } from "./config";
import { plugins } from "./plugins/registry";
import { initializeAppStoreContext } from "./store";
import { initializePluginSlices } from "./store/plugin-store";

/** Handle returned by init() so the host page can unmount the app. */
export interface AppHandle {
  /** Unmount the React app and clean up. */
  unmount: () => void;
}

/**
 * Mount the AI Editorial Assistant into the given DOM element.
 *
 * @param target - CSS selector string or DOM element to mount into.
 * @param config - Partial runtime config (merged over sensible defaults).
 * @returns A handle with an unmount() method.
 */
export async function init(
  target: string | HTMLElement,
  config: Partial<AppConfig> = {},
): Promise<AppHandle> {
  // Resolve the mount node from a selector or direct reference.
  const container =
    typeof target === "string" ? document.querySelector(target) : target;

  if (!container) {
    throw new Error(
      `[ai-editorial-assistant] Mount target not found: ${String(target)}`,
    );
  }

  // Mark the container so the scoped CSS reset can target it. The
  // reset (in index.css @layer reset) uses [data-ai-app] to strip
  // host-page styles from all elements inside the app.
  container.setAttribute("data-ai-app", "");

  // Store config so the rest of the app can read it via getConfig().
  setConfig(config);

  // Write the host-provided context into the store, then rehydrate the
  // matching persisted scope before any plugin slices read from storage.
  await initializeAppStoreContext(config.userId ?? null, config.nodeId ?? null);

  // Hydrate plugin store slices before the first render. Merges each
  // plugin's initialState with any values already persisted in localStorage.
  initializePluginSlices(plugins);

  // Mount the React tree.
  const root: Root = createRoot(container);
  root.render(
    <StrictMode>
      <App />
    </StrictMode>,
  );

  return {
    unmount: () => root.unmount(),
  };
}
