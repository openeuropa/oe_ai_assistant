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
  /** Confirmed selection per composer panel, keyed by panel id. */
  selections: Record<string, string>;
  /** Whether the artifact pane is collapsed to a slim rail (transient). */
  isArtifactCollapsed: boolean;
}

export const draftingSliceConfig: PluginSliceConfig<DraftingSliceState> = {
  initialState: {
    plan: [],
    draftedFields: {},
    selections: {},
    isArtifactCollapsed: false,
  },
  // Nothing is persisted client side. Confirmed selections are rehydrated
  // from the backend on mount, and the conversation lives server side.
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

/** Reads the confirmed selection for a composer panel. */
export function useDraftingSelection(panelId: string): string {
  return useDraftingSlice().selections[panelId] ?? "";
}

/** Writes the confirmed selection for a composer panel. */
export function setDraftingSelection(panelId: string, value: string): void {
  const current = getDraftingState().selections;
  setDraftingState({ selections: { ...current, [panelId]: value } });
}
