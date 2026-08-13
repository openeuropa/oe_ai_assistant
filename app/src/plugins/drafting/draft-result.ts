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

/** Downloadable file behind a document descriptor. */
export interface DraftDocumentFile {
  /** Absolute or site-relative URL serving the file. */
  url: string;
  /** The file name shown on the download control. */
  name: string;
  /** Mime type, e.g. "application/pdf"; drives kind icons and type labels. */
  mime?: string;
  /** File size in bytes. */
  size?: number;
}

/** Document descriptor snapshot stored on a draft. */
export interface DraftDocumentSnapshot {
  id: string;
  title: string;
  /** "context" (briefing material) or "publishable" (asset placed into content). */
  category: string;
  summary?: string;
  meta?: unknown;
  /** Download details, once the documents backend provides them. */
  file?: DraftDocumentFile;
}

/** The editorial context captured when a draft was generated. */
export interface DraftContext {
  tone: DraftToneSnapshot | null;
  template: DraftTemplateSnapshot | null;
  documents: DraftDocumentSnapshot[];
}

/** Normalized draft result: versioned shape or legacy flat fields map. */
export interface ParsedDraftResult {
  /** Numeric version when the versioned shape is detected; null for legacy. */
  version: number | null;
  /** Editorial context present in versioned shape; null for legacy. */
  context: DraftContext | null;
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
 * Parses a draft_content tool-call result into a normalised shape.
 *
 * Versioned detection: an object with a numeric `version` and an object
 * `fields`. Everything else that is object-like is treated as a legacy flat
 * fields map. Null/undefined/non-object inputs produce empty fields.
 */
export function parseDraftResult(result: unknown): ParsedDraftResult {
  if (!isPlainObject(result)) {
    return { version: null, context: null, fields: {} };
  }

  const hasNumericVersion = typeof result["version"] === "number";
  const hasObjectFields = isPlainObject(result["fields"]);

  if (hasNumericVersion && hasObjectFields) {
    return {
      version: result["version"] as number,
      context: normaliseContext(result["context"]),
      fields: result["fields"] as Record<string, unknown>,
    };
  }

  // Legacy flat map: the whole result object is the fields map.
  return { version: null, context: null, fields: result };
}
