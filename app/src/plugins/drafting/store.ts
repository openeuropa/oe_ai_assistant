/**
 * Zustand store slice for the drafting plugin.
 *
 * Tracks the progress of the drafting runs and the raw drafted field values
 * from the AI agent. The conversation itself is persisted server
 * side against the editorial session, so nothing here is persisted.
 */

import type { PluginSliceConfig } from "@/store/plugin-slice-config";
import {
  getPluginState,
  setPluginState,
  usePluginSlice,
} from "@/store/plugin-store";
import type {
  DraftContext,
  DraftGroupProgress,
  DraftRunStatus,
  ParsedDraftResult,
} from "./draft-result";

const PLUGIN_ID = "drafting";

/**
 * What one drafting run reported about itself.
 *
 * The run streams its progress, so this is the only place the card can read
 * it from: the tool call settles with a bare name and version, and the stored
 * record reaches the browser on the next reload.
 */
export interface DraftRunProgress {
  version: number;
  status: DraftRunStatus;
  groups: DraftGroupProgress[];
  /** What the editor set up, as the run read it when it started. */
  context: DraftContext | null;
  /** The error of a run that failed outside any group. */
  error?: string;
}

/**
 * A draft the run reported while it streamed.
 *
 * The time comes with it because the browser cannot work it out: a reload
 * reads the time off the row the site stamped, and a clock reading taken here
 * would disagree with that.
 */
export interface LiveDraft extends ParsedDraftResult {
  /** RFC 3339 time the site stored the draft; null when none was reported. */
  createdAt: string | null;
}

export interface DraftingSliceState {
  /**
   * The runs seen in this session, keyed by the id of the tool call that
   * asked for the draft (transient). A turn renders its own card from the
   * entry under its own call id, so an earlier turn keeps the run it
   * reported.
   */
  runs: Record<string, DraftRunProgress>;
  /**
   * The calls the run in flight settles, which is what the progress events
   * of that run update. Empty between turns.
   */
  activeCallIds: string[];
  /** Raw drafted field values keyed by field machine name. */
  draftedFields: Record<string, unknown>;
  /**
   * Drafts reported live through the data-drafted-fields channel event,
   * keyed by version (transient). The tool-call part's own result never
   * carries the fields, so this is the only way the rail/save flow sees a
   * draft produced in the current session before a reload rehydrates the
   * thread with the join getMessages() performs server side.
   */
  liveDrafts: Record<number, LiveDraft>;
  /** Confirmed selection per composer panel, keyed by panel id. */
  selections: Record<string, string>;
  /** Whether the artifact pane is collapsed to a slim rail (transient). */
  isArtifactCollapsed: boolean;
  /** Version of the draft currently shown in the pane; null for none/legacy. */
  activeDraftVersion: number | null;
}

export const draftingSliceConfig: PluginSliceConfig<DraftingSliceState> = {
  initialState: {
    runs: {},
    activeCallIds: [],
    draftedFields: {},
    liveDrafts: {},
    selections: {},
    isArtifactCollapsed: false,
    activeDraftVersion: null,
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
