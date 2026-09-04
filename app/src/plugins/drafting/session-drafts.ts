/**
 * Session drafts index derived from the assistant-ui thread.
 *
 * Every completed draft_content tool call in the thread is one draft.
 * The thread is the single source of truth: it covers both the
 * rehydrated transcript and drafts produced live, so the index stays
 * correct during the session and after a reload.
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
  /** Menu label, e.g. "Draft 2"; plain "Draft" for legacy entries. */
  label: string;
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
 * Returns true when the value is a plain object with at least one key.
 */
function hasKeys(value: unknown): value is Record<string, unknown> {
  return (
    typeof value === "object" &&
    value !== null &&
    !Array.isArray(value) &&
    Object.keys(value).length > 0
  );
}

/**
 * Extracts the drafts from thread messages, sorted by version with
 * legacy (unversioned) drafts kept in transcript order at the front.
 */
export function extractSessionDrafts(
  messages: readonly ThreadMessageLikeShape[],
): SessionDraft[] {
  const drafts: SessionDraft[] = [];

  for (const message of messages) {
    for (const part of message.content ?? []) {
      if (part.type !== "tool-call" || part.toolName !== "draft_content") {
        continue;
      }
      // Prefer the persisted result; fall back to args.fields when a
      // rehydrated trace stored an empty result (mirrors the tool UI).
      const raw = hasKeys(part.result)
        ? part.result
        : (part.args?.["fields"] ?? {});
      const parsed = parseDraftResult(raw);
      if (Object.keys(parsed.fields).length === 0) {
        continue;
      }
      drafts.push({
        ...parsed,
        label: parsed.version !== null ? `Draft ${parsed.version}` : "Draft",
        createdAt: message.createdAt ?? null,
      });
    }
  }

  // Version order; legacy drafts have no version and sort to the front
  // in their original transcript order (stable sort).
  return drafts.sort((a, b) => (a.version ?? 0) - (b.version ?? 0));
}

/** Reads the drafts index from the current thread. */
export function useSessionDrafts(): SessionDraft[] {
  const messages = useAuiState((s) => s.thread.messages);
  return useMemo(
    () => extractSessionDrafts(messages as readonly ThreadMessageLikeShape[]),
    [messages],
  );
}

/** Opens a draft in the artifact pane, expanding the pane if needed. */
export function openSessionDraft(draft: SessionDraft): void {
  setDraftingState({
    draftedFields: draft.fields,
    activeDraftVersion: draft.version,
    isArtifactCollapsed: false,
  });
}
