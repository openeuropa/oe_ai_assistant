/**
 * Session drafts index derived from the assistant-ui thread.
 *
 * Every tool call whose result carries a versioned draft is one draft.
 * The thread is the single source of truth: it covers both the rehydrated
 * transcript and drafts produced live, so the index stays correct during
 * the session and after a reload. Revisions are numbered under the draft
 * they revise, so a session reads 1.0, 1.1, 2.0.
 */

import { useAuiState } from "@assistant-ui/react";
import { useMemo } from "react";
import { type DraftContext, parseDraftResult } from "./draft-result";
import { setDraftingState } from "./store";

/** One entry in the session drafts index. */
export interface SessionDraft {
  /** Draft version number; null for legacy unversioned drafts. */
  version: number | null;
  /** Editorial context captured when the draft was generated. */
  context: DraftContext | null;
  /** The field values for this draft, keyed by field name. */
  fields: Record<string, unknown>;
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
 * Extracts the drafts from thread messages, sorted by version with
 * legacy (unversioned) drafts kept in transcript order at the front.
 */
export function extractSessionDrafts(
  messages: readonly ThreadMessageLikeShape[],
): SessionDraft[] {
  const drafts: { draft: SessionDraft; major: number; minor: number }[] = [];
  const majors = new Map<number, number>();
  const minors = new Map<number, number>();

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
      // A revision joins the group of the draft it started from. Anything
      // else, a new draft or a revision of a draft no longer in the thread,
      // opens a group of its own.
      const root = parsed.revisionOf;
      let major: number;
      let minor: number;
      if (root !== null && majors.has(root)) {
        major = majors.get(root) as number;
        minor = (minors.get(root) ?? 0) + 1;
        minors.set(root, minor);
      } else {
        major = majors.size + 1;
        minor = 0;
        if (parsed.version !== null) {
          majors.set(parsed.version, major);
          minors.set(parsed.version, 0);
        }
      }
      const label = `${major}.${minor}`;
      drafts.push({
        draft: {
          ...parsed,
          label,
          name: `Draft ${label}`,
          createdAt: message.createdAt ?? null,
        },
        major,
        minor,
      });
    }
  }

  // Revisions follow the draft they belong to, whatever was drafted in
  // between.
  drafts.sort((a, b) => a.major - b.major || a.minor - b.minor);
  return drafts.map((entry) => entry.draft);
}

/** Reads the drafts index from the current thread. */
export function useSessionDrafts(): SessionDraft[] {
  const messages = useAuiState((s) => s.thread.messages);
  return useMemo(
    () => extractSessionDrafts(messages as readonly ThreadMessageLikeShape[]),
    [messages],
  );
}

/** Reads the name of one draft from the current thread. */
export function useDraftName(version: number | null): string {
  const drafts = useSessionDrafts();
  if (version === null) {
    return "Draft";
  }
  return drafts.find((draft) => draft.version === version)?.name ?? "Draft";
}

/** Opens a draft in the artifact pane, expanding the pane if needed. */
export function openSessionDraft(draft: SessionDraft): void {
  setDraftingState({
    draftedFields: draft.fields,
    activeDraftVersion: draft.version,
    isArtifactCollapsed: false,
  });
}
