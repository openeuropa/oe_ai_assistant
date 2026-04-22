/**
 * Zustand slice for the agent-test plugin.
 */
import type { PluginSliceConfig } from "@/store/plugin-slice-config";
import {
  getPluginState,
  setPluginState,
  usePluginSlice,
} from "@/store/plugin-store";
import type { ChatMessage, DraftResult, PlanStep } from "./types";

const PLUGIN_ID = "agent-test";

export interface AgentTestSliceState {
  messages: ChatMessage[];
  /** Text being streamed from the assistant, shown as a typing indicator. */
  streamingText: string;
  plan: PlanStep[];
  draft: DraftResult | null;
  status: "idle" | "streaming" | "done" | "error";
  error: string | null;
}

export const agentTestSliceConfig: PluginSliceConfig<AgentTestSliceState> = {
  initialState: {
    messages: [],
    streamingText: "",
    plan: [],
    draft: null,
    status: "idle",
    error: null,
  },
  // Don't persist streaming state across page reloads.
  partialize: () => ({}),
};

/** Hook for React components. */
export function useAgentTestSlice(): AgentTestSliceState {
  return (
    usePluginSlice<AgentTestSliceState>(PLUGIN_ID) ??
    agentTestSliceConfig.initialState
  );
}

/** Getter for async callbacks outside React. */
export function getAgentTestState(): AgentTestSliceState {
  return (
    getPluginState<AgentTestSliceState>(PLUGIN_ID) ??
    agentTestSliceConfig.initialState
  );
}

/** Setter for mutations. */
export function setAgentTestState(partial: Partial<AgentTestSliceState>): void {
  setPluginState(PLUGIN_ID, partial as Record<string, unknown>);
}
