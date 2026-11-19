/**
 * How the card of a gated call answers the question it asks.
 *
 * The decision runs as a turn on the assistant-ui runtime, and that runtime
 * is owned by the plugin root. A tool UI renders deep inside the thread and
 * cannot reach it, so the root passes down the one callback the card needs.
 */

import { createContext, use } from "react";

/** What the editor answered about one gated call. */
export type Decision = "approve" | "reject";

/** Answers one call that waits for the editor. */
export type ApprovalDecision = (callId: string, decision: Decision) => void;

/** A card rendered outside the plugin root cannot decide anything. */
const ApprovalDecisionContext = createContext<ApprovalDecision>(() => {});

export const ApprovalDecisionProvider = ApprovalDecisionContext.Provider;

/** The callback the card of a gated call answers through. */
export function useApprovalDecision(): ApprovalDecision {
  return use(ApprovalDecisionContext);
}
