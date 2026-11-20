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

/** One source a report cites, as the subagent's result lists it. */
export interface SubagentCitation {
  documentId: string;
  page: number;
  /** Whether the cited page was found to back the claim. */
  verified: boolean;
}

/** Where the ledger holds the task of a call, read after a reload. */
export interface SubagentLive {
  status: string;
  deliveryState: string;
}

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
  /** A remark of the subagent about its run. */
  note: string;
  /** The sources the report cites. */
  citations: SubagentCitation[];
  /** The ledger state the transcript joined onto the call, if any. */
  live: SubagentLive | null;
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

/** Reads the citations of a result, dropping the entries that are not one. */
function parseCitations(raw: unknown): SubagentCitation[] {
  if (!Array.isArray(raw)) return [];
  const citations: SubagentCitation[] = [];
  for (const entry of raw) {
    if (typeof entry !== "object" || entry === null) continue;
    const item = entry as Record<string, unknown>;
    const id = item["document_id"];
    const page = item["page"];
    if (
      (typeof id !== "number" && typeof id !== "string") ||
      typeof page !== "number"
    ) {
      continue;
    }
    citations.push({
      documentId: String(id),
      page,
      verified: item["verified"] === true,
    });
  }
  return citations;
}

/** Reads the ledger state the transcript joined onto a result. */
function parseLive(raw: unknown): SubagentLive | null {
  if (typeof raw !== "object" || raw === null) return null;
  const item = raw as Record<string, unknown>;
  const status = text(item, "status");
  return status === ""
    ? null
    : { status, deliveryState: text(item, "deliveryState") };
}

/**
 * Parses a subagent tool result.
 *
 * @returns The normalized result, or null when the value is not one: a
 *   plain object, or its JSON text, that names a task and carries a known
 *   status.
 */
export function parseSubagentResult(value: unknown): SubagentCallResult | null {
  // A result that streamed in is the JSON text the tool returned. One loaded
  // from the transcript is already an object.
  let result = value;
  if (typeof value === "string") {
    try {
      result = JSON.parse(value);
    } catch {
      return null;
    }
  }
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
    note: text(source, "note"),
    citations: parseCitations(source["citations"]),
    live: parseLive(source["live"]),
  };
}

/** Tells whether the result is the pending answer of a background call. */
export function isPendingSubagentResult(result: unknown): boolean {
  return parseSubagentResult(result)?.status === "pending";
}

/** A piece of a report: plain markdown, or a marker that cites a page. */
export type ReportToken =
  | { type: "text"; text: string }
  | {
      type: "citation";
      documentId: string;
      page: number;
      verified: boolean;
      /** The chip label: the document title and the page. */
      label: string;
    };

const MARKER = /\[doc:(\d+)\s+p\.(\d+)\]/g;

/**
 * Splits a report into text and citation tokens.
 *
 * @param report The markdown of the report, with [doc:<id> p.<n>] markers.
 * @param citations The citations the result lists, which say what was verified.
 * @param titles The titles of the session documents, keyed by document id.
 */
export function splitReport(
  report: string,
  citations: SubagentCitation[],
  titles: Record<string, string>,
): ReportToken[] {
  const tokens: ReportToken[] = [];
  let last = 0;
  for (const match of report.matchAll(MARKER)) {
    const documentId = match[1] as string;
    const page = Number(match[2]);
    if (match.index > last) {
      tokens.push({ type: "text", text: report.slice(last, match.index) });
    }
    tokens.push({
      type: "citation",
      documentId,
      page,
      verified: citations.some(
        (citation) =>
          citation.documentId === documentId &&
          citation.page === page &&
          citation.verified,
      ),
      label: `${titles[documentId] ?? `Document ${documentId}`} · p.${page}`,
    });
    last = match.index + match[0].length;
  }
  if (last < report.length) {
    tokens.push({ type: "text", text: report.slice(last) });
  }
  return tokens;
}
