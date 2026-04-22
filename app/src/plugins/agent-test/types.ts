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

/** SSE event types from the agent_test backend. */
export type SseEvent =
  | { type: "start"; data: { messageId: string } }
  | { type: "start-step"; data: { stepId?: string } }
  | { type: "text-delta"; data: { textDelta: string } }
  | { type: "finish-step"; data: { stepId?: string } }
  | { type: "data-plan"; data: PlanStep[] }
  | { type: "data-drafted-fields"; data: DraftResult }
  | { type: "error"; data: { errorText: string; step?: string } }
  | { type: "finish"; data: { finishReason: string } };
