/**
 * Maps a persisted transcript into assistant-ui seed messages.
 *
 * Used by the drafting runtime's history adapter to rehydrate the thread on
 * mount. Text turns become text parts; tool calls become tool-call parts; event
 * items (role "event") become assistant messages carrying an editorial_event
 * tool-call part so they are visible in the thread.
 */

import type { ThreadMessageLike } from "@assistant-ui/react";
import type { SessionMessage } from "@/api/session-messages";
import { parseDraftResult } from "./draft-result";

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
 * Returns null when the entry has nothing to show (no text, no tool calls,
 * and not an event item).
 */
export function toThreadMessage(
  message: SessionMessage,
  index: number,
): ThreadMessageLike | null {
  // Event items are surfaced as assistant messages with a single editorial_event
  // tool-call part so they appear as annotated steps in the thread.
  if (message.role === "event") {
    const part = {
      type: "tool-call",
      toolCallId: `event-${index}`,
      toolName: "editorial_event",
      args: {
        eventType: message.type,
        summary: message.summary,
        at: message.at,
      },
      result: {},
    };
    return {
      role: "assistant",
      content: [part],
    } as unknown as ThreadMessageLike;
  }

  const parts: Array<Record<string, unknown>> = [];

  if (message.content) {
    parts.push({ type: "text", text: message.content });
  }

  let toolIndex = 0;
  for (const call of message.toolCalls ?? []) {
    const name = call.function?.name;
    if (!name) continue;

    if (name === "draft_content") {
      // Parse the result so callers receive normalised fields in args; the
      // raw result is forwarded as-is so the ToolUI renderer can parse it too.
      const parsed = parseDraftResult(call.result);
      parts.push({
        type: "tool-call",
        toolCallId: `draft-${index}-${toolIndex++}`,
        toolName: "draft_content",
        args: { fields: parsed.fields },
        result: call.result ?? {},
      });
    } else {
      // General tool call: forward name, safe-parsed args, and raw result.
      parts.push({
        type: "tool-call",
        toolCallId: `tool-${index}-${toolIndex++}`,
        toolName: name,
        args: safeParseArgs(call.function?.arguments),
        result: call.result ?? {},
      });
    }
  }

  if (parts.length === 0) {
    return null;
  }

  // The parts are valid assistant-ui content; cast past the wide union.
  // The author's display name travels in the custom metadata so avatars
  // and the participants list can attribute the turn; the persisted
  // creation time becomes createdAt so timestamps survive reloads.
  return {
    role: message.role as "user" | "assistant",
    content: parts,
    ...(message.at ? { createdAt: new Date(message.at) } : {}),
    ...(message.userName
      ? { metadata: { custom: { userName: message.userName } } }
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
