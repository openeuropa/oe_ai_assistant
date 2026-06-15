/**
 * Zustand store slice for the drafting plugin.
 *
 * Tracks the current thread ID, orchestration plan steps,
 * and raw drafted field values from the AI agent.
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
  /** Thread ID for conversation continuity. */
  threadId: string | null;
  /** Orchestration plan steps (transient). */
  plan: PlanStep[];
  /** Raw drafted field values keyed by field machine name. */
  draftedFields: Record<string, unknown>;
}

export const draftingSliceConfig: PluginSliceConfig<DraftingSliceState> = {
  initialState: {
    threadId: null,
    plan: [],
    draftedFields: {},
  },
  /** Only persist thread ID. */
  partialize: (state) =>
    ({ threadId: state.threadId }) as unknown as Partial<DraftingSliceState>,
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
