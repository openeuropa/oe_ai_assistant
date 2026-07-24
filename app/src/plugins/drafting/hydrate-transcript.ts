/**
 * Maps a persisted transcript into assistant-ui seed messages.
 *
 * Used by the drafting runtime's history adapter to rehydrate the thread on
 * mount. Text turns become text parts; a draft_content tool call becomes a
 * tool-call part carrying the drafted fields, so it renders as a clickable
 * trace that repopulates the artifact panel. Other tool calls are ignored.
 */

import type { ThreadMessageLike } from "@assistant-ui/react";
import type { SessionMessage } from "@/api/session-messages";

/**
 * Maps a single transcript entry to an assistant-ui message.
 *
 * Returns null when the entry has nothing to show (empty text and no
 * draft_content tool call).
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
    if (call.function?.name !== "draft_content") {
      continue;
    }
    const fields = call.result ?? {};
    parts.push({
      type: "tool-call",
      toolCallId: `draft-${index}-${toolIndex++}`,
      toolName: "draft_content",
      args: { fields },
      result: fields,
    });
  }
  if (parts.length === 0) {
    return null;
  }
  // The parts are valid assistant-ui content; cast past the wide union.
  return {
    role: message.role as "user" | "assistant",
    content: parts,
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
