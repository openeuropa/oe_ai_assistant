/**
 * Global application state (Zustand).
 *
 * Holds state shared across the shell and all plugins: which plugin is
 * active, user/node context from the CMS, and in-app notifications.
 * Plugin-owned state lives under `pluginStates[pluginId]`.
 *
 * Persisted to localStorage via Zustand's `persist` middleware so the
 * editor can close the browser and resume where they left off. Transient
 * UI state (e.g. sidebar open/closed) is excluded from persistence via
 * `partialize`. Each plugin can also specify its own partialize function
 * to control which parts of its slice are persisted.
 *
 * Server-side persistence (syncing state to the backend API) will be
 * added later when the API layer is in place.
 */

import { create } from "zustand";
import { persist } from "zustand/middleware";

/** A user-facing notification displayed in the shell. */
export interface Notification {
  id: string;
  type: "info" | "success" | "warning" | "error";
  message: string;
}

/** Full shape of the global store, including actions. */
interface AppState {
  // -- Persisted state --

  /** ID of the currently active plugin (matches PluginDefinition.id). */
  activePluginId: string | null;
  /** Authenticated user ID from the CMS session. */
  userId: string | null;
  /** CMS content node ID the editor is working on. */
  nodeId: string | null;
  /** Queue of notifications shown to the user. */
  notifications: Notification[];

  // -- Plugin state --

  /**
   * Plugin-owned state slices, keyed by plugin ID.
   * Each plugin manages its own slice; other plugins may read but
   * should not write to slices they do not own.
   */
  pluginStates: Record<string, Record<string, unknown>>;

  // -- Transient UI state (not persisted) --

  /** Whether the sidebar navigation is expanded. */
  isSidebarOpen: boolean;

  // -- Actions --

  setActivePlugin: (id: string) => void;
  setUserContext: (userId: string | null, nodeId: string | null) => void;
  addNotification: (notification: Notification) => void;
  removeNotification: (id: string) => void;
  clearNotifications: () => void;
  setSidebarOpen: (open: boolean) => void;
  /** Shallow-merge partial state into a plugin's slice. */
  setPluginState: (pluginId: string, partial: Record<string, unknown>) => void;
}

// -- Plugin partialize registry --
// Stores per-plugin functions that filter which state keys are persisted.
// Populated by initializePluginSlices() at startup via registerPluginPartialize().

/** Map of plugin ID to its partialize filter function. */
const partializeRegistry = new Map<
  string,
  (state: Record<string, unknown>) => Record<string, unknown>
>();

/**
 * Register a partialize function for a plugin's store slice.
 * Called during plugin initialization to control which parts of the
 * plugin's state are persisted to localStorage.
 */
export function registerPluginPartialize(
  pluginId: string,
  fn: (state: Record<string, unknown>) => Record<string, unknown>,
): void {
  partializeRegistry.set(pluginId, fn);
}

/**
 * Apply plugin partialize functions to filter persisted state.
 * For each plugin slice, if a partialize function is registered,
 * only the selected keys are persisted. Otherwise the full slice
 * is persisted.
 */
function partializePluginStates(
  pluginStates: Record<string, Record<string, unknown>>,
): Record<string, Record<string, unknown>> {
  const result: Record<string, Record<string, unknown>> = {};

  for (const [pluginId, state] of Object.entries(pluginStates)) {
    const fn = partializeRegistry.get(pluginId);
    if (fn) {
      const filtered = fn(state);
      // Only include the slice if there are keys to persist.
      if (Object.keys(filtered).length > 0) {
        result[pluginId] = filtered;
      }
    } else {
      // No partialize function: persist the whole slice.
      result[pluginId] = state;
    }
  }

  return result;
}

export const useAppStore = create<AppState>()(
  persist(
    (set) => ({
      activePluginId: null,
      userId: null,
      nodeId: null,
      notifications: [],
      pluginStates: {},
      isSidebarOpen: false,

      setActivePlugin: (id) => set({ activePluginId: id }),
      setUserContext: (userId, nodeId) => set({ userId, nodeId }),
      addNotification: (notification) =>
        set((state) => ({
          notifications: [...state.notifications, notification],
        })),
      removeNotification: (id) =>
        set((state) => ({
          notifications: state.notifications.filter((n) => n.id !== id),
        })),
      clearNotifications: () => set({ notifications: [] }),
      setSidebarOpen: (open) => set({ isSidebarOpen: open }),
      setPluginState: (pluginId, partial) =>
        set((state) => ({
          pluginStates: {
            ...state.pluginStates,
            [pluginId]: { ...state.pluginStates[pluginId], ...partial },
          },
        })),
    }),
    {
      name: "ai-editorial-assistant",
      // Only persist durable state; transient UI flags are excluded.
      // Plugin slices are filtered through their own partialize functions.
      partialize: (state) => ({
        activePluginId: state.activePluginId,
        userId: state.userId,
        nodeId: state.nodeId,
        notifications: state.notifications,
        pluginStates: partializePluginStates(state.pluginStates),
      }),
    },
  ),
);
