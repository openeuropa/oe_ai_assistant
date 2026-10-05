/**
 * Saved draft versions derived from the assistant-ui thread.
 *
 * A save is a save_draft tool call, and its result names the version it wrote.
 * The call is part of the stored conversation, so reading the thread keeps the
 * index correct during the session and after a reload.
 */

import { useAuiState } from "@assistant-ui/react";
import { useMemo } from "react";

/** Minimal shape of a thread message part this module inspects. */
interface ToolPartLike {
  type?: string;
  toolName?: string;
  result?: Record<string, unknown>;
}

/** Minimal shape of a thread message this module inspects. */
interface ThreadMessageLikeShape {
  content?: readonly ToolPartLike[];
}

/** Collects the versions that a save_draft call wrote to the content item. */
export function extractSavedVersions(
  messages: readonly ThreadMessageLikeShape[],
): Set<number> {
  const saved = new Set<number>();
  for (const message of messages) {
    for (const part of message.content ?? []) {
      if (part.type !== "tool-call" || part.toolName !== "save_draft") {
        continue;
      }
      const version = part.result?.["version"];
      // A call that was refused, or is still waiting for a decision, wrote
      // nothing: only a node id says the save happened.
      if (
        part.result?.["nodeId"] !== undefined &&
        typeof version === "number"
      ) {
        saved.add(version);
      }
    }
  }
  return saved;
}

/** Reads the saved versions from the current thread. */
export function useSavedVersions(): Set<number> {
  const messages = useAuiState((s) => s.thread.messages);
  return useMemo(
    () => extractSavedVersions(messages as readonly ThreadMessageLikeShape[]),
    [messages],
  );
}
