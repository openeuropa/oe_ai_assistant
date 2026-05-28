/**
 * Types for the agent-test plugin.
 */

/** A single chat message in the conversation. */
export interface ChatMessage {
  role: "user" | "assistant";
  text: string;
}

/** A step in the orchestration plan. */
export interface PlanStep {
  stepId: string;
  status: "pending" | "in_progress" | "done" | "error";
}

/** The consolidated draft result. */
export type DraftResult = Record<string, unknown>;

/**
 * SSE event types from the agent_test backend.
 *
 * Uses the flat format where type and payload fields are at the
 * same level (e.g. {"type": "text-delta", "textDelta": "..."}).
 * Custom events (data-plan, data-drafted-fields) carry their
 * payload under an explicit "data" key.
 */
export type SseEvent =
  | { type: "start"; messageId: string }
  | { type: "start-step"; stepId?: string }
  | { type: "text-delta"; textDelta: string }
  | { type: "finish-step"; stepId?: string }
  | { type: "data-plan"; data: PlanStep[] }
  | { type: "data-drafted-fields"; data: DraftResult }
  | { type: "error"; errorText: string; step?: string }
  | { type: "finish"; finishReason: string };
