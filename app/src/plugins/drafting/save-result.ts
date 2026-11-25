/**
 * Save result parser.
 *
 * Normalises the result of a drafting_save_draft tool call into how the save
 * ended. A live reply streams the result as a JSON string, and a reloaded
 * transcript returns it as an object. A call with no result at all has not
 * been decided yet, and readSaveState() reports that case. The outcomes
 * themselves are what parseSaveResult() reads.
 */

/** How a save ended, once the server recorded an outcome. */
export type SaveResult =
  | { status: "saved"; version: number; nodeId: string; previewUrl: string }
  | { status: "not_saved"; error: string }
  | { status: "cancelled" };

/** What the card shows, including the states that are not outcomes. */
export type SaveState =
  | { status: "running" }
  | { status: "waiting"; version: number }
  | SaveResult;

/** The arguments the model called drafting_save_draft with. */
export interface SaveArgs {
  version?: number;
  /** The server says this call waits for the editor's decision. */
  awaitingDecision?: boolean;
}

/**
 * The sentences that mark a cancel arriving as text rather than a record.
 *
 * A cancel reaches the card as plain text in two ways: the agent wraps a
 * rejected call in its own sentence, and the site stores our own cancel
 * text for the model to read. Neither carries a flag, so match on what
 * they say.
 */
const CANCEL_MARKERS = ["TOOL NOT EXECUTED", "cancelled this save"];

/** Joins the text of a tool output's blocks. */
function blocksToText(blocks: unknown): string {
  if (!Array.isArray(blocks)) return "";

  return blocks
    .map((block) =>
      typeof block === "object" && block !== null
        ? String((block as Record<string, unknown>)["text"] ?? "")
        : "",
    )
    .join("");
}

/** Reads the result of a save call, live or reloaded. */
export function parseSaveResult(result: unknown): SaveResult {
  let value = result;
  if (typeof value === "string") {
    const text = value;
    try {
      value = JSON.parse(text);
    } catch {
      // The agent answers an invalid call with the message alone, so
      // plain text that is not one of the cancel sentences is an error
      // the editor should read.
      return CANCEL_MARKERS.some((marker) => text.includes(marker))
        ? { status: "cancelled" }
        : { status: "not_saved", error: text };
    }
  }
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return { status: "cancelled" };
  }
  const record = value as Record<string, unknown>;
  if (typeof record["nodeId"] === "string" && record["nodeId"] !== "") {
    return {
      status: "saved",
      version: Number(record["version"]),
      nodeId: record["nodeId"],
      previewUrl: String(record["previewUrl"] ?? ""),
    };
  }
  if (record["cancelled"] === true) {
    return { status: "cancelled" };
  }
  if (typeof record["error"] === "string") {
    return { status: "not_saved", error: record["error"] };
  }
  // A call the agent could not finish comes back in the tool output
  // shape, with is_error set and the text in blocks. Show that text rather
  // than a cancel, which would claim a decision the editor never took.
  const interrupted = blocksToText(record["blocks"]);
  if (record["is_error"] === true && interrupted !== "") {
    return { status: "not_saved", error: interrupted };
  }

  return { status: "cancelled" };
}

/**
 * Reads what the card shows for one save call.
 *
 * A decided call always carries a result: the tool returns one, and the
 * site stores one for a cancel too. So a call with no result has not been
 * decided, and the card asks the question rather than reporting an outcome
 * nobody recorded.
 *
 * @param isRunning
 *   Whether the reply that made the call is still streaming.
 */
export function readSaveState(
  args: SaveArgs,
  result: unknown,
  isRunning: boolean,
): SaveState {
  if (result !== undefined) {
    return parseSaveResult(result);
  }
  if (isRunning && args.awaitingDecision !== true) {
    return { status: "running" };
  }

  return { status: "waiting", version: args.version ?? 0 };
}
