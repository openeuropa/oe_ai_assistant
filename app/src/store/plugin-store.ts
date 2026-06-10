/**
 * Plugin store slice infrastructure.
 *
 * Provides the mechanism for plugins to register their own state
 * slices within the global Zustand store. Each plugin's state lives
 * under `pluginStates[pluginId]` in the app store, giving plugins
 * full ownership of their slice while allowing cross-plugin reads.
 *
 * Usage:
 * 1. Define a PluginSliceConfig in your plugin's store.ts
 * 2. Add it to PluginDefinition.storeSlice in the registry
 * 3. Call initializePluginSlices(plugins) at app startup
 * 4. Use usePluginSlice<T>(id) to read, getPluginState/setPluginState to write
 */

import type { PluginDefinition } from "@/plugins/types";
import { registerPluginPartialize, useAppStore } from "./index";

// Re-export PluginSliceConfig for convenience.
export type { PluginSliceConfig } from "./plugin-slice-config";

/**
 * Initialize all plugin store slices.
 *
 * Iterates the plugin list, and for each plugin that declares a
 * storeSlice, sets its initial state in the global store. Must be
 * called once before the app renders (typically in main.tsx).
 *
 * If a plugin's slice already has persisted state (from localStorage),
 * the persisted values take precedence over initialState for the keys
 * that were persisted.
 */
export function initializePluginSlices(plugins: PluginDefinition[]): void {
  const currentStates = useAppStore.getState().pluginStates;

  for (const plugin of plugins) {
    if (!plugin.storeSlice) continue;

    const { initialState, partialize } = plugin.storeSlice;

    // Register the partialize function before initializing the slice so
    // the first persisted write already uses the plugin's scoped filter.
    if (partialize) {
      registerPluginPartialize(
        plugin.id,
        partialize as (
          state: Record<string, unknown>,
        ) => Record<string, unknown>,
      );
    }

    // Merge: persisted values override initial state, but initial state
    // fills in any keys that were not persisted (e.g. transient state).
    const persisted = currentStates[plugin.id];
    const merged = persisted
      ? { ...initialState, ...persisted }
      : { ...initialState };

    useAppStore.getState().setPluginState(plugin.id, merged);
  }
}

/**
 * Hook to read a plugin's store slice with type safety.
 *
 * Returns the plugin's current state cast to T, or undefined if
 * the plugin has not registered a store slice.
 *
 * @template T - Expected shape of the plugin's state.
 * @param pluginId - The plugin's unique identifier.
 */
export function usePluginSlice<T extends object>(
  pluginId: string,
): T | undefined {
  return useAppStore((s) => s.pluginStates[pluginId] as T | undefined);
}

/**
 * Read a plugin's store slice outside of React (e.g. in async callbacks).
 *
 * @template T - Expected shape of the plugin's state.
 * @param pluginId - The plugin's unique identifier.
 */
export function getPluginState<T extends object>(
  pluginId: string,
): T | undefined {
  return useAppStore.getState().pluginStates[pluginId] as T | undefined;
}

/**
 * Update a plugin's store slice outside of React (e.g. in async callbacks).
 * Performs a shallow merge into the plugin's existing state.
 *
 * @param pluginId - The plugin's unique identifier.
 * @param partial - Partial state to merge.
 */
export function setPluginState(
  pluginId: string,
  partial: Record<string, unknown>,
): void {
  useAppStore.getState().setPluginState(pluginId, partial);
}
