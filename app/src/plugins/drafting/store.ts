/**
 * Zustand store slice for the drafting plugin.
 *
 * Tracks the orchestration plan steps and raw drafted field values
 * from the AI agent. The conversation itself is persisted server
 * side against the editorial session, so nothing here is persisted.
 */

import type { PluginSliceConfig } from "@/store/plugin-slice-config";
import {
  getPluginState,
  setPluginState,
  usePluginSlice,
} from "@/store/plugin-store";

const PLUGIN_ID = "drafting";

/** A step in the orchestration plan. */
export interface PlanStep {
  stepId: string;
  label: string;
  status: "pending" | "in_progress" | "done" | "error";
}

export interface DraftingSliceState {
  /** Orchestration plan steps (transient). */
  plan: PlanStep[];
  /** Raw drafted field values keyed by field machine name. */
  draftedFields: Record<string, unknown>;
}

export const draftingSliceConfig: PluginSliceConfig<DraftingSliceState> = {
  initialState: {
    plan: [],
    draftedFields: {},
  },
  /** Nothing is persisted; the conversation lives on the backend. */
  partialize: () => ({}) as Partial<DraftingSliceState>,
};

/** Typed read hook for React components. */
export function useDraftingSlice(): DraftingSliceState {
  const state = usePluginSlice<DraftingSliceState>(PLUGIN_ID);
  return state ?? draftingSliceConfig.initialState;
}

/** Typed getter for async callbacks outside React. */
export function getDraftingState(): DraftingSliceState {
  return (
    getPluginState<DraftingSliceState>(PLUGIN_ID) ??
    draftingSliceConfig.initialState
  );
}

/** Typed setter for mutations. */
export function setDraftingState(partial: Partial<DraftingSliceState>): void {
  setPluginState(PLUGIN_ID, partial as Record<string, unknown>);
}
