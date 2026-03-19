/**
 * Notes plugin store slice.
 *
 * Defines the notes plugin's state that lives in the global Zustand
 * store under `pluginStates["notes"]`. Tracks which view (list, create,
 * edit) is currently active.
 *
 * Server data (the notes themselves) is managed by TanStack Query,
 * not the store. This slice only holds client-side UI state.
 *
 * View state is transient, so nothing is persisted across page reloads.
 */

import type { PluginSliceConfig } from "@/store/plugin-slice-config";
import { setPluginState, usePluginSlice } from "@/store/plugin-store";

/** Plugin ID used as the store namespace key. */
const PLUGIN_ID = "notes";

/** Which view is currently displayed in the notes plugin. */
export type NotesView =
  | { mode: "list" }
  | { mode: "create" }
  | { mode: "edit"; noteId: string };

/** Shape of the notes plugin's store slice. */
export interface NotesSliceState {
  /** The currently active view in the notes plugin. */
  view: NotesView;
}

/** Store slice configuration registered in the plugin definition. */
export const notesSliceConfig: PluginSliceConfig<NotesSliceState> = {
  initialState: {
    view: { mode: "list" },
  },
  // View state is transient, not persisted.
  partialize: () => ({}),
};

/**
 * Hook to read the notes plugin's store slice.
 * Returns the typed state with a fallback to initial values.
 */
export function useNotesSlice(): NotesSliceState {
  const state = usePluginSlice<NotesSliceState>(PLUGIN_ID);
  return state ?? notesSliceConfig.initialState;
}

/**
 * Update the notes slice. Shallow-merges partial state into the
 * existing slice.
 */
export function setNotesState(partial: Partial<NotesSliceState>): void {
  setPluginState(PLUGIN_ID, partial as Record<string, unknown>);
}
