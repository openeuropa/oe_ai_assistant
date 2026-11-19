/**
 * Saved draft versions derived from the assistant-ui thread.
 *
 * Every approved drafting_save_draft call carries the version it persisted
 * in its result, both while the reply streams and after a reload. Reading
 * the thread keeps the index correct during the session and after a reload.
 */

import { useAuiState } from "@assistant-ui/react";
import { useMemo } from "react";
import { parseSaveResult } from "./save-result";

/** Minimal shape of a thread message part this module inspects. */
interface EventPartLike {
  type?: string;
  toolName?: string;
  result?: unknown;
}

/** Minimal shape of a thread message this module inspects. */
interface ThreadMessageLikeShape {
  content?: readonly EventPartLike[];
}

/** Collects the versions of the saves the editor approved in the thread. */
export function extractSavedVersions(
  messages: readonly ThreadMessageLikeShape[],
): Set<number> {
  const saved = new Set<number>();
  for (const message of messages) {
    for (const part of message.content ?? []) {
      if (
        part.type !== "tool-call" ||
        part.toolName !== "drafting_save_draft"
      ) {
        continue;
      }
      const result = parseSaveResult(part.result);
      if (result.status === "saved") {
        saved.add(result.version);
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
