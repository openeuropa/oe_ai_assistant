/**
 * Saved draft versions derived from the assistant-ui thread.
 *
 * Saving a draft splices an event part carrying the version it wrote, so
 * the index follows the saves of this page load. The transcript carries
 * no such part, so a reload empties the index.
 */

import { useAuiState } from "@assistant-ui/react";
import { useMemo } from "react";

/** Minimal shape of a thread message part this module inspects. */
interface EventPartLike {
  type?: string;
  toolName?: string;
  args?: Record<string, unknown>;
}

/** Minimal shape of a thread message this module inspects. */
interface ThreadMessageLikeShape {
  content?: readonly EventPartLike[];
}

/** Collects the versions named by "save" events in the thread. */
export function extractSavedVersions(
  messages: readonly ThreadMessageLikeShape[],
): Set<number> {
  const saved = new Set<number>();
  for (const message of messages) {
    for (const part of message.content ?? []) {
      if (part.type !== "tool-call" || part.toolName !== "editorial_event") {
        continue;
      }
      const version = part.args?.["version"];
      if (part.args?.["eventType"] === "save" && typeof version === "number") {
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
