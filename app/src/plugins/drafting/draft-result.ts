/**
 * Draft result parser.
 *
 * Normalises the value stored as a draft_content tool-call result into a
 * consistent shape. The backend persists two formats:
 *
 *   - Versioned: `{version, context, fields}` introduced when provenance
 *     tracking was added. The context captures the tone, template, and
 *     documents that were active when the draft was generated.
 *   - Legacy flat: any other object-like value where the object itself is
 *     the fields map.
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

/** Where one field group of a draft stands. */
export type DraftGroupStatus = "pending" | "drafting" | "drafted" | "failed";

/** One field group of a draft, with the progress the run reported for it. */
export interface DraftGroupProgress {
  /** The group id, which is unique within the content type. */
  id: string;
  /** The group label, as the content type defines it. */
  label: string;
  status: DraftGroupStatus;
  /** The error recorded for a failed group. Absent for every other status. */
  error?: string;
}

/**
 * Where the drafting run that produces a draft stands.
 *
 * A running draft has no fields to display yet, and a failed one never gets
 * any, so the card opens the artifact pane only when the run is drafted. A
 * stored record is never running: the run streams its progress instead.
 */
export type DraftRunStatus = "running" | "drafted" | "failed";

/** Normalized draft result: versioned shape or legacy flat fields map. */
export interface ParsedDraftResult {
  /** Numeric version when the versioned shape is detected; null for legacy. */
  version: number | null;
  /** Editorial context present in versioned shape; null for legacy. */
  context: DraftContext | null;
  /** The groups the run drafted; empty for a record that stored none. */
  groups: DraftGroupProgress[];
  /** What the run that wrote this record ended as. */
  status: DraftRunStatus;
  /** The error of a run that failed outside any group; empty otherwise. */
  error: string;
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
 * Normalises the groups of a stored record.
 *
 * Elements are stored verbatim by the backend. A record written before the
 * groups were stored carries none, and the card then shows no group row.
 */
function normaliseGroups(raw: unknown): DraftGroupProgress[] {
  if (!Array.isArray(raw)) {
    return [];
  }
  return raw as DraftGroupProgress[];
}

/**
 * Parses a draft_content tool-call result into a normalised shape.
 *
 * Versioned detection: an object with a numeric `version`. A run that failed
 * stores no field, so the field map cannot settle this. Everything else that
 * is object-like is treated as a legacy flat fields map. Null/undefined and
 * non-object inputs produce empty fields.
 */
export function parseDraftResult(result: unknown): ParsedDraftResult {
  const empty = {
    version: null,
    context: null,
    groups: [],
    status: "drafted" as const,
    error: "",
  };

  if (!isPlainObject(result)) {
    return { ...empty, fields: {} };
  }

  if (typeof result["version"] === "number") {
    return {
      version: result["version"],
      context: normaliseContext(result["context"]),
      groups: normaliseGroups(result["groups"]),
      status: result["status"] === "failed" ? "failed" : "drafted",
      error: typeof result["error"] === "string" ? result["error"] : "",
      fields: isPlainObject(result["fields"]) ? result["fields"] : {},
    };
  }

  // Legacy flat map: the whole result object is the fields map.
  return { ...empty, fields: result };
}
