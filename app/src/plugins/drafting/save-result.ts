/**
 * Save result parser.
 *
 * Normalises the result of a drafting_save_draft tool call into how the save
 * ended. A live reply streams the result as a JSON string, and a reloaded
 * transcript returns it as an object. A cancel leaves no result, or only
 * Neuron's rejection text, an empty list, or the record of an interrupted
 * call, so anything that names neither a node nor an error reads as cancelled.
 */

/** How a save ended. */
export type SaveResult =
  | { status: "saved"; version: number; nodeId: string; previewUrl: string }
  | { status: "not_saved"; error: string }
  | { status: "cancelled" };

/** Reads the result of a save call, live or reloaded. */
export function parseSaveResult(result: unknown): SaveResult {
  let value = result;
  if (typeof value === "string") {
    try {
      value = JSON.parse(value);
    } catch {
      return { status: "cancelled" };
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
  if (typeof record["error"] === "string") {
    return { status: "not_saved", error: record["error"] };
  }
  return { status: "cancelled" };
}
