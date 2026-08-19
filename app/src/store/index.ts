/**
 * Global application state (Zustand).
 *
 * Holds state shared across the shell and all plugins: which plugin is
 * active and in-app notifications. Host context (user, session, node)
 * lives in the config singleton, not here. Plugin-owned state lives
 * under `pluginStates[pluginId]`.
 *
 * Persisted to localStorage via Zustand's `persist` middleware so the
 * editor can close the browser and resume where they left off. Persistence
 * is scoped per editorial session so state cannot bleed between sessions
 * in the same browser, while collaborating users on the same session
 * share it. Transient UI state (e.g. sidebar
 * open/closed) is excluded from persistence via `partialize`. Each plugin
 * can also specify its own partialize function to control which parts of
 * its slice are persisted.
 *
 * Server-side persistence (syncing state to the backend API) will be
 * added later when the API layer is in place.
 */

import { create } from "zustand";
import { createJSONStorage, persist } from "zustand/middleware";

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
  /** Pending-work flags reported by plugins, keyed by reporting source. */
  pendingWork: Record<string, boolean>;

  // -- Actions --

  setActivePlugin: (id: string) => void;
  addNotification: (notification: Notification) => void;
  removeNotification: (id: string) => void;
  clearNotifications: () => void;
  setSidebarOpen: (open: boolean) => void;
  /** Report whether a source (usually a plugin) has work in flight. */
  setPendingWork: (source: string, pending: boolean) => void;
  /** Shallow-merge partial state into a plugin's slice. */
  setPluginState: (pluginId: string, partial: Record<string, unknown>) => void;
}

type PersistedAppState = Pick<
  AppState,
  "activePluginId" | "notifications" | "pluginStates"
>;

const STORAGE_KEY_PREFIX = "ai-editorial-assistant";
const PREINIT_STORAGE_KEY = `${STORAGE_KEY_PREFIX}:pending-init`;

// Prevent writes while switching storage keys. Without this guard, the
// temporary reset to empty durable state would overwrite the target scope
// in localStorage before rehydration has a chance to read from it.
let arePersistWritesPaused = false;

function createPersistedState(): PersistedAppState {
  return {
    activePluginId: null,
    notifications: [],
    pluginStates: {},
  };
}

function createInitialState() {
  return {
    ...createPersistedState(),
    isSidebarOpen: true,
    pendingWork: {},
  };
}

/**
 * Build the storage key for the current host context.
 *
 * Keyed by editorial session only: sessions are collaborative, so any
 * user with access to a session must pick up the same persisted state.
 * Access to the session itself is enforced server-side before the app
 * is ever rendered.
 */
export function getScopedStorageKey(sessionId: string): string {
  return `${STORAGE_KEY_PREFIX}:session:${encodeURIComponent(sessionId)}`;
}

const scopedStorage = createJSONStorage<PersistedAppState>(() => {
  const storage = window.localStorage;

  return {
    getItem: (name) => storage.getItem(name),
    setItem: (name, value) => {
      if (arePersistWritesPaused) return;
      storage.setItem(name, value);
    },
    removeItem: (name) => {
      if (arePersistWritesPaused) return;
      storage.removeItem(name);
    },
  };
});

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
      ...createInitialState(),

      setActivePlugin: (id) => set({ activePluginId: id }),
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
      setPendingWork: (source, pending) =>
        set((state) => ({
          pendingWork: { ...state.pendingWork, [source]: pending },
        })),
      setPluginState: (pluginId, partial) =>
        set((state) => ({
          pluginStates: {
            ...state.pluginStates,
            [pluginId]: { ...state.pluginStates[pluginId], ...partial },
          },
        })),
    }),
    {
      name: PREINIT_STORAGE_KEY,
      storage: scopedStorage,
      skipHydration: true,
      // Only persist durable state; transient UI flags are excluded.
      // Plugin slices are filtered through their own partialize functions.
      partialize: (state) => ({
        activePluginId: state.activePluginId,
        notifications: state.notifications,
        pluginStates: partializePluginStates(state.pluginStates),
      }),
    },
  ),
);

/**
 * True when any source reports in-flight or unsent work. Used by the
 * shell exit guard to block navigation until the session is idle.
 */
export function useHasPendingWork(): boolean {
  return useAppStore((s) =>
    Object.values(s.pendingWork).some((pending) => pending),
  );
}

/**
 * Prepare the store for a specific host context before rendering.
 *
 * This updates the persistence key, clears any in-memory durable state
 * from a previous context, and then rehydrates from the matching scoped
 * localStorage entry.
 */
export async function initializeAppStoreContext(
  sessionId: string,
): Promise<void> {
  arePersistWritesPaused = true;

  try {
    useAppStore.persist.setOptions({
      name: getScopedStorageKey(sessionId),
    });
    useAppStore.setState(createPersistedState());
    await useAppStore.persist.rehydrate();
  } finally {
    arePersistWritesPaused = false;
  }
}
