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
  /**
   * Monotonically increasing counter used to remount the runtime subtree.
   * Incrementing causes the history adapter to refetch get-messages, which
   * makes newly recorded event chips appear at their chronological position
   * without a full page reload. Excluded from partialize (transient).
   */
  timelineVersion: number;
}

export const draftingSliceConfig: PluginSliceConfig<DraftingSliceState> = {
  initialState: {
    plan: [],
    draftedFields: {},
    selections: {},
    timelineVersion: 0,
  },
  // Nothing is persisted client side. Confirmed selections are rehydrated
  // from the backend on mount, and the conversation lives server side.
  // timelineVersion is also excluded: it resets to 0 on every page load.
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

/**
 * Increments timelineVersion by one, triggering a remount of the runtime
 * subtree keyed by this value. The remount causes the history adapter to
 * refetch the persisted transcript so newly recorded event chips appear
 * immediately at their chronological position.
 */
export function bumpTimelineVersion(): void {
  const current = getDraftingState().timelineVersion;
  setDraftingState({ timelineVersion: current + 1 });
}
