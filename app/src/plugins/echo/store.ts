/**
 * Echo plugin store slice.
 *
 * Defines the echo plugin's state that lives in the global Zustand
 * store under `pluginStates["echo"]`. Contains the streamed words,
 * stream status, and error state.
 *
 * All echo state is transient (SSE stream data), so nothing is
 * persisted across page reloads.
 */

import type { PluginSliceConfig } from "@/store/plugin-slice-config";
import {
  getPluginState,
  setPluginState,
  usePluginSlice,
} from "@/store/plugin-store";
import type { EchoStatus } from "./types";

/** Plugin ID used as the store namespace key. */
const PLUGIN_ID = "echo";

/** Shape of the echo plugin's store slice. */
export interface EchoSliceState {
  /** Accumulated words received from the SSE stream. */
  words: string[];
  /** Current status of the echo stream lifecycle. */
  status: EchoStatus;
  /** Error message if the stream failed, null otherwise. */
  error: string | null;
}

/** Store slice configuration registered in the plugin definition. */
export const echoSliceConfig: PluginSliceConfig<EchoSliceState> = {
  initialState: {
    words: [],
    status: "idle",
    error: null,
  },
  // Echo state is transient (live stream data), so exclude it all
  // from persistence.
  partialize: () => ({}),
};

/**
 * Hook to read the echo plugin's store slice.
 * Returns the typed state with a fallback to initial values.
 */
export function useEchoSlice(): EchoSliceState {
  const state = usePluginSlice<EchoSliceState>(PLUGIN_ID);
  return state ?? echoSliceConfig.initialState;
}

/**
 * Read the echo slice outside of React (e.g. inside async callbacks).
 */
export function getEchoState(): EchoSliceState {
  return (
    getPluginState<EchoSliceState>(PLUGIN_ID) ?? echoSliceConfig.initialState
  );
}

/**
 * Update the echo slice. Shallow-merges partial state into the
 * existing slice.
 */
export function setEchoState(partial: Partial<EchoSliceState>): void {
  setPluginState(PLUGIN_ID, partial as Record<string, unknown>);
}
