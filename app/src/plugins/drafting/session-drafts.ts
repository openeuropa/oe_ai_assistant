/**
 * Session drafts index derived from the assistant-ui thread.
 *
 * Every completed drafting_draft_content tool call in the thread is one draft.
 * The thread is the single source of truth: it covers both the
 * rehydrated transcript and drafts produced live, so the index stays
 * correct during the session and after a reload.
 */

import { useAuiState } from "@assistant-ui/react";
import { useMemo } from "react";
import {
  type DraftContext,
  type DraftGroupProgress,
  parseDraftResult,
} from "./draft-result";
import { type LiveDraft, setDraftingState, useDraftingSlice } from "./store";

/** One entry in the session drafts index. */
export interface SessionDraft {
  /** Draft version number; null for legacy unversioned drafts. */
  version: number | null;
  /** Editorial context captured when the draft was generated. */
  context: DraftContext | null;
  /** The groups the run drafted; empty for a draft that recorded none. */
  groups: DraftGroupProgress[];
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
 *
 * `liveDrafts` carries drafts reported live through the data-drafted-fields
 * channel event, keyed by version: the tool-call part's own result never
 * carries the fields (the backend keeps it small so the LLM never replays a
 * whole content type back to itself), so a draft produced in the current
 * session is otherwise invisible until a reload rehydrates the thread with
 * the join getMessages() performs server side. A version already present
 * from the thread takes precedence, so a reload's real result is never
 * shadowed by a stale live entry.
 */
export function extractSessionDrafts(
  messages: readonly ThreadMessageLikeShape[],
  liveDrafts?: Readonly<Record<number, LiveDraft>>,
): SessionDraft[] {
  const drafts: SessionDraft[] = [];

  for (const message of messages) {
    for (const part of message.content ?? []) {
      if (
        part.type !== "tool-call" ||
        part.toolName !== "drafting_draft_content"
      ) {
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

  const knownVersions = new Set(drafts.map((d) => d.version));
  for (const liveDraft of Object.values(liveDrafts ?? {})) {
    if (
      knownVersions.has(liveDraft.version) ||
      Object.keys(liveDraft.fields).length === 0
    ) {
      continue;
    }
    drafts.push({
      ...liveDraft,
      label: `Draft ${liveDraft.version}`,
      // The run reports the time the site stamped on the draft, so the card
      // and the pane header carry it without waiting for a reload.
      createdAt: liveDraft.createdAt ? new Date(liveDraft.createdAt) : null,
    });
  }

  // Version order; legacy drafts have no version and sort to the front
  // in their original transcript order (stable sort).
  return drafts.sort((a, b) => (a.version ?? 0) - (b.version ?? 0));
}

/** Reads the drafts index from the current thread. */
export function useSessionDrafts(): SessionDraft[] {
  const messages = useAuiState((s) => s.thread.messages);
  const liveDrafts = useDraftingSlice().liveDrafts;
  return useMemo(
    () =>
      extractSessionDrafts(
        messages as readonly ThreadMessageLikeShape[],
        liveDrafts,
      ),
    [messages, liveDrafts],
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
