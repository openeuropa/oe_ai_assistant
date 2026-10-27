/**
 * Saved draft versions derived from the assistant-ui thread.
 *
 * A save is a save_draft tool call, and its result names the version it wrote.
 * The call is part of the stored conversation, so reading the thread keeps the
 * index correct during the session and after a reload.
 */

import { useAuiState } from "@assistant-ui/react";
import { useMemo } from "react";
import { useDraftingSlice } from "./store";
import { decodeToolResult } from "./tool-result";

/** Minimal shape of a thread message part this module inspects. */
interface ToolPartLike {
  type?: string;
  toolName?: string;
  result?: unknown;
}

/** Minimal shape of a thread message this module inspects. */
interface ThreadMessageLikeShape {
  content?: readonly ToolPartLike[];
}

/** Collects the versions that a save_draft call wrote to the content item. */
export function extractSavedVersions(
  messages: readonly ThreadMessageLikeShape[],
  editRevisions: Record<number, number> = {},
): Set<number> {
  const saved = new Map<number, number>();
  const edited = { ...editRevisions };
  for (const message of messages) {
    for (const part of message.content ?? []) {
      if (part.type !== "tool-call") {
        continue;
      }
      const result = decodeToolResult(part.result);
      const draft = result?.draft as
        | { version?: number; editRevision?: number }
        | undefined;
      if (typeof draft?.version === "number") {
        edited[draft.version] = Math.max(
          edited[draft.version] ?? 0,
          draft.editRevision ?? 0,
        );
      }
      const version = result?.version;
      // A call that was refused, or is still waiting for a decision, wrote
      // nothing: only a node id says the save happened.
      if (
        part.toolName === "save_draft" &&
        result?.nodeId !== undefined &&
        typeof version === "number"
      ) {
        saved.set(
          version,
          typeof result.editRevision === "number" ? result.editRevision : 0,
        );
      }
    }
  }
  return new Set(
    [...saved]
      .filter(([version, revision]) => revision >= (edited[version] ?? 0))
      .map(([version]) => version),
  );
}

/** Reads the saved versions from the current thread. */
export function useSavedVersions(): Set<number> {
  const messages = useAuiState((s) => s.thread.messages);
  const { editableDrafts } = useDraftingSlice();
  return useMemo(
    () =>
      extractSavedVersions(
        messages as readonly ThreadMessageLikeShape[],
        Object.fromEntries(
          Object.entries(editableDrafts).map(([version, draft]) => [
            version,
            draft.revision,
          ]),
        ),
      ),
    [messages, editableDrafts],
  );
}
