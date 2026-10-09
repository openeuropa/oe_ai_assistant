/**
 * Draft result parser.
 *
 * Normalises the draft stored on the completing draft_group call into a
 * consistent shape. The backend persists `{version, major, minor, context,
 * fields}`: the major and minor numbers place the draft under the one it
 * revises, and the context captures the tone, template, and documents that
 * were active when it was generated.
 */

import type { components } from "@/api/schema";

/** Tone snapshot stored on a draft: id, label, and the raw guidelines. */
export interface DraftToneSnapshot {
  id: string;
  label: string;
  /** The raw prompt/guidelines text, if captured. */
  prompt?: string;
}

/** Template snapshot stored on a draft. */
export interface DraftTemplateSnapshot {
  id: string;
  label: string;
}

/** Document descriptor snapshot stored on a draft, as the API contract defines it. */
export type DraftDocumentSnapshot =
  components["schemas"]["DraftingDocumentSnapshot"];

/** The editorial context captured when a draft was generated. */
export interface DraftContext {
  tone: DraftToneSnapshot | null;
  template: DraftTemplateSnapshot | null;
  documents: DraftDocumentSnapshot[];
}

/** Normalised draft result. */
export interface ParsedDraftResult {
  /** Session-wide draft number, the one the API addresses. */
  version: number;
  /** Group number: the draft it revises shares it. */
  major: number;
  /** Position within the group, 0 for the draft that opened it. */
  minor: number;
  /** The editorial context captured when the draft was generated. */
  context: DraftContext;
  /** The field values for this draft, keyed by field name. */
  fields: Record<string, unknown>;
}

/**
 * Returns true when the value is a plain object (not null, not an array).
 */
function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

/**
 * Normalises the raw document list from a persisted context object.
 *
 * Accepts any array-shaped value and passes through elements verbatim.
 * Returns an empty array when the input is not an array.
 */
function normaliseDocuments(raw: unknown): DraftDocumentSnapshot[] {
  if (!Array.isArray(raw)) {
    return [];
  }
  // Elements are stored verbatim by the backend; cast to the known shape.
  return raw as DraftDocumentSnapshot[];
}

/**
 * Normalises the raw context object from a persisted versioned draft.
 *
 * Missing or non-object context falls back to null tone, null template, and
 * an empty documents array so callers always get a consistent structure.
 */
function normaliseContext(raw: unknown): DraftContext {
  const ctx = isPlainObject(raw) ? raw : {};
  return {
    tone: isPlainObject(ctx["tone"])
      ? (ctx["tone"] as unknown as DraftToneSnapshot)
      : null,
    template: isPlainObject(ctx["template"])
      ? (ctx["template"] as unknown as DraftTemplateSnapshot)
      : null,
    documents: normaliseDocuments(ctx["documents"]),
  };
}

/**
 * Parses the draft stored on a draft_group call into a normalised shape.
 *
 * Returns null for anything but an object with numeric `version`, `major`
 * and `minor` and an object `fields`.
 */
export function parseDraftResult(result: unknown): ParsedDraftResult | null {
  if (
    !isPlainObject(result) ||
    typeof result["version"] !== "number" ||
    typeof result["major"] !== "number" ||
    typeof result["minor"] !== "number" ||
    !isPlainObject(result["fields"])
  ) {
    return null;
  }

  return {
    version: result["version"],
    major: result["major"],
    minor: result["minor"],
    context: normaliseContext(result["context"]),
    fields: result["fields"],
  };
}
