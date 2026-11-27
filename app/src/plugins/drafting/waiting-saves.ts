/**
 * The save calls that still wait for the editor's decision.
 *
 * A decision runs as its own turn, so its result lands on a new part and
 * the part that asked never gets one. That is why a call is read across
 * the whole thread. It waits until some later part answers it.
 */

import { useAuiState } from "@assistant-ui/react";
import { useMemo } from "react";
import type { SaveArgs } from "./save-result";

/** Minimal shape of a thread message part this module inspects. */
interface ToolCallPartLike {
  type?: string;
  toolName?: string;
  toolCallId?: string;
  args?: Record<string, unknown>;
  result?: unknown;
}

/** Minimal shape of a thread message this module inspects. */
interface ThreadMessageLikeShape {
  content?: readonly ToolCallPartLike[];
}

/** One save call the editor has not answered. */
export interface WaitingSave {
  /** The id the decision names. */
  callId: string;
  /** The draft version the call asks to save. */
  version: string;
}

/** Collects the save calls that nothing has answered. */
export function extractWaitingSaves(
  messages: readonly ThreadMessageLikeShape[],
): WaitingSave[] {
  const answered = new Set<string>();
  const waiting = new Map<string, string>();
  for (const message of messages) {
    for (const part of message.content ?? []) {
      if (
        part.type !== "tool-call" ||
        part.toolName !== "drafting_save_draft"
      ) {
        continue;
      }
      const callId = part.toolCallId ?? "";
      if (part.result !== undefined) {
        answered.add(callId);
        continue;
      }
      const args = (part.args ?? {}) as SaveArgs;
      waiting.set(callId, args.version ?? "");
    }
  }
  for (const callId of answered) {
    waiting.delete(callId);
  }

  return [...waiting].map(([callId, version]) => ({ callId, version }));
}

/** The save calls of the current thread that wait for a decision. */
export function useWaitingSaves(): WaitingSave[] {
  const messages = useAuiState((s) => s.thread.messages);

  return useMemo(
    () => extractWaitingSaves(messages as readonly ThreadMessageLikeShape[]),
    [messages],
  );
}
