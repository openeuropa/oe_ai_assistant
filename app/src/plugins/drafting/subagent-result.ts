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
 *   status, or the {tool, error} answer of the tool error handler, which
 *   reads as a failed result with no task.
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
  // The tool error handler answers a call that threw with {tool, error}.
  if (
    typeof source["tool"] === "string" &&
    typeof source["error"] === "string" &&
    !("status" in source)
  ) {
    return {
      taskId: "",
      subagent: "",
      status: "failed",
      report: "",
      question: "",
      error: source["error"],
      note: "",
      citations: [],
      live: null,
    };
  }
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

/** How well the pages a marker cites are backed. */
export type CitationStatus = "verified" | "partial" | "unverified" | "neutral";

/** A piece of a report: plain markdown, or a marker that cites pages. */
export type ReportToken =
  | { type: "text"; text: string }
  | {
      type: "citation";
      documentId: string;
      /** The first page the marker cites. */
      page: number;
      /** Every page the marker cites, a range expanded. */
      pages: number[];
      /** Whether every cited page was found to back the claim. */
      verified: boolean;
      /** Whether some, but not all, of the cited pages were. */
      partial: boolean;
      /** The status the chip is drawn with. */
      status: CitationStatus;
      /** The chip label: the document title and the pages. */
      label: string;
    };

/** The most pages a range of a marker expands to. */
const MAX_RANGE = 20;

/** A marker: [doc:N p.A], [doc:N p.A–B], [doc:N pp.A–B], [doc:N p.A, p.B]. */
export const MARKER =
  /\[doc:(\d+)\s+pp?\.\s*(\d+(?:\s*[–-]\s*\d+)?(?:\s*,\s*(?:pp?\.\s*)?\d+(?:\s*[–-]\s*\d+)?)*)\]/g;

/** Expands the pages of a marker, such as "4–5, 9", into page numbers. */
export function expandPages(spec: string): number[] {
  const pages: number[] = [];
  for (const part of spec.split(",")) {
    const bounds = part.replace(/^\s*pp?\.\s*/, "").split(/\s*[–-]\s*/);
    const first = Number(bounds[0]);
    const last =
      bounds[1] === undefined
        ? first
        : Math.max(first, Math.min(Number(bounds[1]), first + MAX_RANGE - 1));
    for (let page = first; page <= last; page++) {
      pages.push(page);
    }
  }
  return pages;
}

/** Writes pages as the chip shows them: p.4, p.4–5 or p.4–5, 9. */
export function pagesLabel(pages: number[]): string {
  const runs: string[] = [];
  let start = 0;
  for (let index = 1; index <= pages.length; index++) {
    if (pages[index] !== (pages[index - 1] as number) + 1) {
      const first = pages[start] as number;
      const last = pages[index - 1] as number;
      runs.push(first === last ? String(first) : `${first}–${last}`);
      start = index;
    }
  }
  return `p.${runs.join(", ")}`;
}

/**
 * Splits a text into plain text and citation tokens.
 *
 * @param text The markdown, with [doc:<id> p.<n>] markers.
 * @param titles The titles of the session documents, keyed by document id.
 * @param statusOf Says how well the given pages of a document are backed.
 */
function splitMarkers(
  text: string,
  titles: Record<string, string>,
  statusOf: (documentId: string, pages: number[]) => CitationStatus,
): ReportToken[] {
  const tokens: ReportToken[] = [];
  let last = 0;
  for (const match of text.matchAll(MARKER)) {
    const documentId = match[1] as string;
    const pages = expandPages(match[2] as string);
    if (match.index > last) {
      tokens.push({ type: "text", text: text.slice(last, match.index) });
    }
    const status = statusOf(documentId, pages);
    tokens.push({
      type: "citation",
      documentId,
      page: pages[0] as number,
      pages,
      verified: status === "verified",
      partial: status === "partial",
      status,
      label: `${titles[documentId] ?? `Document ${documentId}`} · ${pagesLabel(pages)}`,
    });
    last = match.index + match[0].length;
  }
  if (last < text.length) {
    tokens.push({ type: "text", text: text.slice(last) });
  }
  return tokens;
}

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
  return splitMarkers(report, titles, (documentId, pages) => {
    const verified = pages.filter((page) =>
      citations.some(
        (citation) =>
          citation.documentId === documentId &&
          citation.page === page &&
          citation.verified,
      ),
    ).length;
    if (verified === 0) return "unverified";
    return verified === pages.length ? "verified" : "partial";
  });
}

/**
 * Splits a text with no verification, such as an editorial reply, into text
 * and citation tokens whose status is neutral.
 */
export function splitCitations(
  text: string,
  titles: Record<string, string>,
): ReportToken[] {
  return splitMarkers(text, titles, () => "neutral");
}

/** Tells whether a text carries at least one citation marker. */
export function hasCitations(text: string): boolean {
  return new RegExp(MARKER.source).test(text);
}
