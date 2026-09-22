/**
 * Session drafts index derived from the assistant-ui thread.
 *
 * Every tool call whose result carries a versioned draft is one draft.
 * The thread is the single source of truth: it covers both the rehydrated
 * transcript and drafts produced live, so the index stays correct during
 * the session and after a reload. The backend numbers revisions under the
 * draft they revise, so a session reads 1.0, 1.1, 2.0.
 */

import { useAuiState } from "@assistant-ui/react";
import { useMemo } from "react";
import { type ParsedDraftResult, parseDraftResult } from "./draft-result";
import { setDraftingState } from "./store";

/** One entry in the session drafts index. */
export interface SessionDraft extends ParsedDraftResult {
  /** Compact grouped number, e.g. "2.1". */
  label: string;
  /** Menu label, e.g. "Draft 2.1". */
  name: string;
  /** When the thread message carrying the draft was created, if known. */
  createdAt: Date | null;
}

/** Minimal shape of a thread message part this module inspects. */
interface ToolCallPartLike {
  type?: string;
  toolName?: string;
  args?: Record<string, unknown>;
  result?: unknown;
}

/** Minimal shape of a thread message this module inspects. */
interface ThreadMessageLikeShape {
  content?: readonly ToolCallPartLike[];
  createdAt?: Date;
}

/**
 * Returns the versioned draft stored on a group call result, if any.
 */
function draftOf(result: unknown): unknown {
  return typeof result === "object" && result !== null && "draft" in result
    ? (result as { draft: unknown }).draft
    : undefined;
}

/**
 * Extracts the drafts from thread messages, revisions under the draft they
 * revise and legacy (unversioned) drafts in transcript order at the front.
 */
export function extractSessionDrafts(
  messages: readonly ThreadMessageLikeShape[],
): SessionDraft[] {
  const drafts: SessionDraft[] = [];

  for (const message of messages) {
    for (const part of message.content ?? []) {
      if (part.type !== "tool-call") {
        continue;
      }
      const draft = draftOf(part.result);
      if (draft === undefined) {
        continue;
      }
      const parsed = parseDraftResult(draft);
      if (Object.keys(parsed.fields).length === 0) {
        continue;
      }
      const label = `${parsed.major}.${parsed.minor}`;
      drafts.push({
        ...parsed,
        label,
        name: `Draft ${label}`,
        createdAt: message.createdAt ?? null,
      });
    }
  }

  // Revisions follow the draft they belong to, whatever was drafted in
  // between.
  drafts.sort((a, b) => a.major - b.major || a.minor - b.minor);
  return drafts;
}

/** Reads the drafts index from the current thread. */
export function useSessionDrafts(): SessionDraft[] {
  const messages = useAuiState((s) => s.thread.messages);
  return useMemo(
    () => extractSessionDrafts(messages as readonly ThreadMessageLikeShape[]),
    [messages],
  );
}

/** Reads one draft from the current thread, or null when it is not there. */
export function useSessionDraft(version: number | null): SessionDraft | null {
  const drafts = useSessionDrafts();
  if (version === null) {
    return null;
  }
  return drafts.find((draft) => draft.version === version) ?? null;
}

/** Reads the name of one draft from the current thread. */
export function useDraftName(version: number | null): string {
  return useSessionDraft(version)?.name ?? "Draft";
}

/** Opens a draft in the artifact pane, expanding the pane if needed. */
export function openSessionDraft(
  draft: Pick<SessionDraft, "fields" | "version">,
): void {
  setDraftingState({
    draftedFields: draft.fields,
    activeDraftVersion: draft.version,
    isArtifactCollapsed: false,
  });
}
