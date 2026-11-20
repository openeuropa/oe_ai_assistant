/**
 * Live state of the subagent runs that stream into a turn.
 *
 * The editorial turn reports a subagent's work as stream events: the run
 * starts, its tool calls report one activity each, its text streams, and the
 * run finishes. These pure functions fold those events into one run per call,
 * which the subagent card of that call reads while it works.
 */

/** Where one tool call of the subagent stands. */
export type SubagentStepStatus = "running" | "done" | "failed";

/** One tool call of the subagent. */
export interface SubagentStep {
  /** The id of the activity that reports the call. */
  id: string;
  tool: string;
  /** The inputs of the call as a JSON string, cut short by the backend. */
  input: string;
  status: SubagentStepStatus;
  /** What the call returned, once it did. */
  summary?: string;
}

/** The status a run has before it ends is one of the first two. */
export type SubagentRunStatus = "running" | "queued" | string;

/** What one subagent run reported about itself. */
export interface SubagentRun {
  taskId: string;
  subagent: string;
  status: SubagentRunStatus;
  steps: SubagentStep[];
  /** The text the subagent streamed so far. */
  text: string;
}

/** The runs seen in this session, keyed by the id of the call that asked. */
export type SubagentRuns = Record<string, SubagentRun>;

const STEP_STATUSES: readonly string[] = ["running", "done", "failed"];

/** Reads a string key of a payload, or an empty string. */
function str(source: Record<string, unknown>, key: string): string {
  const value = source[key];
  return typeof value === "string" ? value : "";
}

/** Tells whether a value is a plain object. */
function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/**
 * Applies one stream event to the runs.
 *
 * @param runs The runs so far.
 * @param name The event name, without the data- prefix of the frame.
 * @param payload The data of the event.
 * @returns The new runs, or the same object when the event is none of the
 *   subagent's or names a run that was never started.
 */
export function applySubagentEvent(
  runs: SubagentRuns,
  name: string,
  payload: unknown,
): SubagentRuns {
  if (!isRecord(payload)) return runs;

  if (name === "workflow-activity") {
    // The activity wraps its data: {id, type, data}. Other types belong to
    // other features, such as the drafting progress.
    if (payload["type"] !== "subagent" || !isRecord(payload["data"])) {
      return runs;
    }
    return applyActivity(runs, str(payload, "id"), payload["data"]);
  }

  const callId = str(payload, "callId");
  if (callId === "") return runs;

  switch (name) {
    case "subagent-started":
    case "subagent-queued": {
      const status = name === "subagent-started" ? "running" : "queued";
      return {
        ...runs,
        [callId]: {
          taskId: str(payload, "taskId"),
          subagent: str(payload, "subagent"),
          status,
          steps: [],
          text: "",
        },
      };
    }
    case "subagent-text": {
      const run = runs[callId];
      if (!run) return runs;
      return {
        ...runs,
        [callId]: { ...run, text: run.text + str(payload, "delta") },
      };
    }
    case "subagent-finished": {
      const run = runs[callId];
      if (!run) return runs;
      return {
        ...runs,
        [callId]: { ...run, status: str(payload, "status") || "completed" },
      };
    }
    default:
      return runs;
  }
}

/** Updates the step an activity reports, or adds it. */
function applyActivity(
  runs: SubagentRuns,
  id: string,
  data: Record<string, unknown>,
): SubagentRuns {
  const callId = str(data, "callId");
  const run = runs[callId];
  if (!run || id === "") return runs;

  const reported = str(data, "status");
  const step: SubagentStep = {
    id,
    tool: str(data, "tool"),
    input: str(data, "input"),
    status: STEP_STATUSES.includes(reported)
      ? (reported as SubagentStepStatus)
      : "running",
    ...(typeof data["summary"] === "string"
      ? { summary: data["summary"] }
      : {}),
  };

  const exists = run.steps.some((existing) => existing.id === id);
  // An update replaces the step in place, so the list keeps the order the
  // calls started in.
  const steps = exists
    ? run.steps.map((existing) =>
        existing.id === id ? { ...existing, ...step } : existing,
      )
    : [...run.steps, step];
  return { ...runs, [callId]: { ...run, steps } };
}

/** Turns a tool name into words: documents_search becomes Documents search. */
export function humanizeToolName(tool: string): string {
  const words = tool.replace(/[_\-.]+/g, " ").trim();
  return words === "" ? tool : words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * Summarizes the inputs of a step in a few words.
 *
 * @param input The inputs as JSON, which the backend may have cut short.
 * @param max The longest summary to return.
 */
export function summarizeStepInput(input: string, max = 80): string {
  let summary = input;
  try {
    const parsed: unknown = JSON.parse(input);
    if (isRecord(parsed)) {
      // The first text value is what the call was about, such as the query.
      const first = Object.values(parsed).find(
        (value) => typeof value === "string" && value !== "",
      );
      summary =
        typeof first === "string"
          ? first
          : Object.values(parsed)
              .map((value) => String(value))
              .join(", ");
    }
  } catch {
    // A cut-short JSON string is shown as it is.
  }
  summary = summary.trim();
  return summary.length > max ? `${summary.slice(0, max - 1)}…` : summary;
}
