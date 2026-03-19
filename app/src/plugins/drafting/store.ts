/**
 * Zustand store slice for the drafting plugin.
 *
 * Tracks the current thread ID and drafted field values
 * received from the agent via AG-UI STATE_SNAPSHOT events.
 * Also tracks which fields the editor has rejected so the
 * agent can regenerate them.
 */

import type { PluginSliceConfig } from "@/store/plugin-slice-config";
import {
  getPluginState,
  setPluginState,
  usePluginSlice,
} from "@/store/plugin-store";

const PLUGIN_ID = "drafting";

/** A single sub-field inside an inline entity (e.g. a contact). */
export interface DraftedSubField {
  label: string;
  value: string;
}

/** A single inline entity instance (e.g. one contact card). */
export interface DraftedInlineEntity {
  bundle: string;
  bundleLabel: string;
  fields: Record<string, DraftedSubField>;
}

/** A single drafted field value from the agent. */
export interface DraftedField {
  label: string;
  value: string;
  type: string;
  /** For inline_form fields: nested entity instances. */
  inlineEntities?: DraftedInlineEntity[];
}

export interface DraftingSliceState {
  /** AG-UI thread ID for conversation continuity. */
  threadId: string | null;
  /** Drafted field values keyed by field machine name. */
  draftedFields: Record<string, DraftedField>;
  /** Set of field names the editor has rejected. */
  rejectedFields: Set<string>;
  /** Whether the user has sent at least one message. */
  hasPrompted: boolean;
}

export const draftingSliceConfig: PluginSliceConfig<DraftingSliceState> = {
  initialState: {
    threadId: null,
    draftedFields: {},
    rejectedFields: new Set(),
    hasPrompted: false,
  },
  /** Only persist thread ID -- drafted fields are transient. */
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

/** Toggle a field's rejected status. */
export function toggleFieldRejected(fieldName: string): void {
  const state = getDraftingState();
  const next = new Set(state.rejectedFields);
  if (next.has(fieldName)) {
    next.delete(fieldName);
  } else {
    next.add(fieldName);
  }
  setDraftingState({ rejectedFields: next });
}
