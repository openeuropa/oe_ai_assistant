/**
 * Parser for the result of a tool call that hands work to a subagent.
 *
 * A call run in the background settles at once with a pending result that
 * names the task. A call run in the foreground settles with the subagent's
 * own result, whose status says how the run ended.
 */

/** Where the work of a subagent call stands. */
export type SubagentCallStatus =
  | "pending"
  | "running"
  | "completed"
  | "needs_input"
  | "failed"
  | "stopped";

/** The normalized result of a subagent tool call. */
export interface SubagentCallResult {
  taskId: string;
  subagent: string;
  status: SubagentCallStatus;
  /** The report of a completed research run. */
  report: string;
  /** The question of a run that needs the editor. */
  question: string;
  /** The reason of a failed run. */
  error: string;
}

const STATUSES: readonly string[] = [
  "pending",
  "running",
  "completed",
  "needs_input",
  "failed",
  "stopped",
];

/** Reads a string key of a plain object, or an empty string. */
function text(source: Record<string, unknown>, key: string): string {
  const value = source[key];
  return typeof value === "string" ? value : "";
}

/**
 * Parses a subagent tool result.
 *
 * @returns The normalized result, or null when the value is not one: a
 *   plain object that names a task and carries a known status.
 */
export function parseSubagentResult(
  result: unknown,
): SubagentCallResult | null {
  if (typeof result !== "object" || result === null || Array.isArray(result)) {
    return null;
  }
  const source = result as Record<string, unknown>;
  const status = text(source, "status");
  const taskId = text(source, "task_id");
  if (taskId === "" || !STATUSES.includes(status)) {
    return null;
  }
  return {
    taskId,
    subagent: text(source, "subagent"),
    status: status as SubagentCallStatus,
    report: text(source, "report"),
    question: text(source, "question"),
    error: text(source, "error"),
  };
}

/** Tells whether the result is the pending answer of a background call. */
export function isPendingSubagentResult(result: unknown): boolean {
  return parseSubagentResult(result)?.status === "pending";
}
