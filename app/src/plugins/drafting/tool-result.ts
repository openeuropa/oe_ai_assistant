/**
 * Tool result decoding.
 *
 * A tool result reaches the thread two ways, and they carry it differently.
 * The stream sends it as the text the tool returned, because that is what the
 * protocol defines a tool output to be. The stored transcript sends it already
 * decoded, because the endpoint reads it out of the conversation. Both end up
 * on the same message part, so every reader decodes through here.
 */

/**
 * Reads a tool result as an object, whichever way it arrived.
 *
 * @param raw The result as the thread part carries it: the text the tool
 *   returned, an object, or nothing while the call is still waiting.
 * @returns The object, or null when there is none to read.
 */
export function decodeToolResult<T = Record<string, unknown>>(
  raw: unknown,
): T | null {
  if (raw === null || raw === undefined) {
    return null;
  }
  if (typeof raw === "string") {
    try {
      const parsed: unknown = JSON.parse(raw);
      return typeof parsed === "object" && parsed !== null
        ? (parsed as T)
        : null;
    } catch {
      return null;
    }
  }

  return typeof raw === "object" ? (raw as T) : null;
}
