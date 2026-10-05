/**
 * Maps a persisted transcript into assistant-ui seed messages.
 *
 * Used by the drafting runtime's history adapter to rehydrate the thread on
 * mount. Text turns become text parts and tool calls become tool-call parts
 * carrying their stored result, or no result when the call is still waiting
 * for the editor to answer it.
 */

import type { ThreadMessageLike } from "@assistant-ui/react";
import type { SessionMessage } from "@/api/session-messages";

/**
 * Safe-parses a JSON string into a plain object.
 *
 * Returns an empty object when the string is absent, empty, or malformed.
 */
function safeParseArgs(raw: string | undefined): Record<string, unknown> {
  if (!raw) return {};
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (
      typeof parsed === "object" &&
      parsed !== null &&
      !Array.isArray(parsed)
    ) {
      return parsed as Record<string, unknown>;
    }
    return {};
  } catch {
    return {};
  }
}

/**
 * Maps a single transcript entry to an assistant-ui message.
 *
 * Returns null when the entry has nothing to show: no text and no tool calls.
 */
export function toThreadMessage(
  message: SessionMessage,
  index: number,
): ThreadMessageLike | null {
  const parts: Array<Record<string, unknown>> = [];

  if (message.content) {
    parts.push({ type: "text", text: message.content });
  }

  let toolIndex = 0;
  for (const call of message.toolCalls ?? []) {
    const name = call.function?.name;
    if (!name) continue;
    // Forward the name, the safe-parsed arguments and the raw result; the
    // tool UI registered for the name reads what it needs from the result.
    // The stored call id is what a decision names, so it is kept as the part's
    // id rather than generated. A call with no result is one that never ran:
    // leaving the result unset is what tells its UI it is still waiting.
    parts.push({
      type: "tool-call",
      toolCallId: call.id ?? `tool-${index}-${toolIndex}`,
      toolName: name,
      args: safeParseArgs(call.function?.arguments),
      ...(call.result === undefined ? {} : { result: call.result }),
    });
    toolIndex += 1;
  }

  if (parts.length === 0) {
    return null;
  }

  // The parts are valid assistant-ui content; cast past the wide union.
  // The author's display name and user id travel in the custom metadata
  // so avatars and the participants list can attribute the turn; the
  // persisted creation time becomes createdAt so timestamps survive
  // reloads.
  return {
    role: message.role as "user" | "assistant",
    content: parts,
    ...(message.at ? { createdAt: new Date(message.at) } : {}),
    ...(message.userName
      ? {
          metadata: {
            custom: { userName: message.userName, userId: message.userId },
          },
        }
      : {}),
  } as unknown as ThreadMessageLike;
}

/**
 * Maps the whole transcript, dropping entries with nothing to show.
 */
export function toThreadMessages(
  messages: SessionMessage[],
): ThreadMessageLike[] {
  return messages
    .map((m, i) => toThreadMessage(m, i))
    .filter((m): m is ThreadMessageLike => m !== null);
}
