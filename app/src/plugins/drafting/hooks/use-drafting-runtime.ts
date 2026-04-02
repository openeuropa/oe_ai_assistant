/**
 * Custom runtime hook that connects assistant-ui to the backend
 * drafting endpoint via the Data Stream Protocol.
 *
 * Uses useDataStreamRuntime from @assistant-ui/react-data-stream
 * which sends POST requests to /api/plugins/drafting/chat and
 * consumes UI Message Stream SSE events. The onData callback
 * intercepts data-drafted-fields events to populate the Zustand
 * drafting store with transformed field values.
 */

import {
  CompositeAttachmentAdapter,
  SimpleImageAttachmentAdapter,
  SimpleTextAttachmentAdapter,
} from "@assistant-ui/react";
import { useDataStreamRuntime } from "@assistant-ui/react-data-stream";
import { useCallback, useEffect, useMemo, useRef } from "react";
import { getConfig } from "@/config";
import type {
  DraftedField,
  DraftedInlineEntity,
  DraftedSubField,
} from "../store";
import { getDraftingState, setDraftingState } from "../store";

// -- Schema types -----------------------------------------------------------

/** A single field definition from the content type schema. */
interface SchemaField {
  name: string;
  label: string;
  type: string;
  widget: string;
  interaction: string;
  cardinality: number;
  inlineForm?: {
    targetBundles: Record<
      string,
      { label: string; groups: { fields: SchemaField[] }[] }
    >;
  };
}

/** Top-level content type schema returned by the API. */
interface ContentTypeSchema {
  contentType: string;
  label: string;
  groups: { label: string; fields: SchemaField[] }[];
}

/** Flattened field lookup keyed by machine name. */
type FieldIndex = Record<string, SchemaField>;

/**
 * Fetches the form-mode content type schema and returns a flat
 * field index keyed by machine name.
 */
async function fetchFieldIndex(
  apiBaseUrl: string,
  entityTypeId: string,
  bundle: string,
): Promise<FieldIndex> {
  try {
    const url = `${apiBaseUrl}/content-schema/${entityTypeId}/${bundle}?mode=form`;
    const res = await fetch(url, { credentials: "include" });
    if (!res.ok) return {};
    const schema = (await res.json()) as ContentTypeSchema;
    const index: FieldIndex = {};
    for (const group of schema.groups) {
      for (const field of group.fields) {
        index[field.name] = field;
      }
    }
    return index;
  } catch {
    return {};
  }
}

// -- Value mapping helpers --------------------------------------------------

/**
 * Derives a human-readable label from a field machine name.
 * Used as fallback when the schema is not available.
 */
function labelFromName(name: string): string {
  return name
    .replace(/^field_/, "")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Converts a raw inline entity object from the LLM into the
 * DraftedInlineEntity shape, using the schema's inline form
 * definition to resolve sub-field labels.
 */
function toInlineEntity(
  raw: Record<string, unknown>,
  schemaField?: SchemaField,
): DraftedInlineEntity {
  // Find the first target bundle definition for sub-field labels.
  const bundles = schemaField?.inlineForm?.targetBundles ?? {};
  const firstBundle = Object.entries(bundles)[0];
  const bundleKey = firstBundle?.[0] ?? "";
  const bundleDef = firstBundle?.[1];
  const subFieldIndex: Record<string, SchemaField> = {};
  if (bundleDef) {
    for (const group of bundleDef.groups) {
      for (const f of group.fields) {
        subFieldIndex[f.name] = f;
      }
    }
  }

  const fields: Record<string, DraftedSubField> = {};
  for (const [key, val] of Object.entries(raw)) {
    if (typeof val === "string" || typeof val === "number") {
      fields[key] = {
        label: subFieldIndex[key]?.label ?? labelFromName(key),
        value: String(val),
      };
    }
  }
  return {
    bundle: bundleKey,
    bundleLabel: bundleDef?.label ?? "",
    fields,
  };
}

/**
 * Transforms raw field values from the LLM into DraftedField objects,
 * using the content type schema to resolve labels, types, and inline
 * entity structures.
 */
function transformFields(
  raw: Record<string, unknown>,
  fieldIndex: FieldIndex,
): Record<string, DraftedField> {
  const fields: Record<string, DraftedField> = {};

  for (const [name, val] of Object.entries(raw)) {
    const schema = fieldIndex[name];
    const label = schema?.label ?? labelFromName(name);
    const fieldType = schema?.type ?? "string";

    // Formatted text: { value: "<p>...</p>", format: "full_html" }
    if (
      val !== null &&
      typeof val === "object" &&
      !Array.isArray(val) &&
      "value" in (val as Record<string, unknown>)
    ) {
      fields[name] = {
        label,
        value: String((val as Record<string, unknown>).value ?? ""),
        type: "html",
      };
      continue;
    }

    // Inline entities: array of objects (e.g. contacts).
    if (Array.isArray(val) && val.length > 0 && typeof val[0] === "object") {
      fields[name] = {
        label,
        value: "",
        type: "inline_form",
        inlineEntities: val.map((item) =>
          toInlineEntity(item as Record<string, unknown>, schema),
        ),
      };
      continue;
    }

    // Simple scalar (string, number, date, select).
    fields[name] = { label, value: String(val ?? ""), type: fieldType };
  }

  return fields;
}

/**
 * Returns an assistant-ui runtime backed by the Data Stream Protocol.
 *
 * The runtime sends POST requests to /api/plugins/drafting/chat
 * and receives UI Message Stream SSE events. assistant-ui handles
 * all event parsing, message rendering, and streaming state. The
 * onData callback intercepts data-drafted-fields events to update
 * the Zustand drafting store with transformed field values.
 */
export function useDraftingRuntime() {
  // Read bundle and entity type from the host page's plugin config.
  const draftingConfig = getConfig().pluginConfig.drafting ?? {};
  const bundle = (draftingConfig.bundle as string) ?? "";
  const entityTypeId = (draftingConfig.entityTypeId as string) ?? "node";

  // Fetch the content type schema once for field label/type resolution.
  const fieldIndexRef = useRef<FieldIndex>({});
  useEffect(() => {
    if (!bundle) return;
    fetchFieldIndex(getConfig().apiBaseUrl, entityTypeId, bundle).then(
      (index) => {
        fieldIndexRef.current = index;
      },
    );
  }, [entityTypeId, bundle]);

  // Track per-field serialized values to detect which field changed.
  const lastFieldValuesRef = useRef<Record<string, string>>({});

  // Accumulate all fields that changed during a single run so we
  // can highlight them when the run finishes.
  const changedFieldsRef = useRef<Set<string>>(new Set());

  // Callback for data-drafted-fields events from the stream.
  // Transforms raw LLM values and merges them into the store.
  const handleDraftedFields = useCallback((raw: Record<string, unknown>) => {
    const incomingFields = transformFields(raw, fieldIndexRef.current);

    // Merge incoming fields with existing ones so that partial
    // updates (e.g. single-field regeneration) don't wipe out
    // fields the backend intentionally omitted.
    const existing = getDraftingState().draftedFields;
    const fields = { ...existing, ...incomingFields };

    // Detect which field changed by comparing per-field serialized
    // values against the previous snapshot. Also accumulate all
    // changed fields for the highlight effect when the run ends.
    let changedField: string | null = null;
    const currentFieldValues: Record<string, string> = {};
    for (const [name, field] of Object.entries(fields)) {
      const fieldSerialized = JSON.stringify(field);
      currentFieldValues[name] = fieldSerialized;
      if (fieldSerialized !== lastFieldValuesRef.current[name]) {
        changedField = name;
        changedFieldsRef.current.add(name);
      }
    }
    lastFieldValuesRef.current = currentFieldValues;

    setDraftingState({
      draftedFields: fields,
      streamingFieldName: changedField,
    });
  }, []);

  // Accept images and common document types as attachments.
  // Files are kept client-side only (no upload); the mock server
  // acknowledges them but does not process them.
  const attachmentAdapter = useMemo(
    () =>
      new CompositeAttachmentAdapter([
        new SimpleImageAttachmentAdapter(),
        new SimpleTextAttachmentAdapter(),
      ]),
    [],
  );

  const runtime = useDataStreamRuntime({
    api: `${getConfig().apiBaseUrl}/plugins/drafting/chat`,
    credentials: "include",
    // Send bundle and entityTypeId in the request body so the
    // backend can load the correct content type schema.
    body: { bundle, entityTypeId },
    adapters: {
      attachments: attachmentAdapter,
    },
    // Handle data-drafted-fields events from the UI message stream.
    // The onData callback fires for any SSE event whose type starts
    // with "data-". The name field has the "data-" prefix stripped.
    onData: (data) => {
      if (data.name === "drafted-fields") {
        handleDraftedFields(data.data as Record<string, unknown>);
      }
    },
    onFinish: () => {
      // When the run finishes, clear the streaming indicator and
      // mark all fields that changed during the run as updated so
      // the UI can highlight them briefly.
      setDraftingState({
        streamingFieldName: null,
        updatedFields: new Set(changedFieldsRef.current),
      });
      // Clear the highlight after a short delay so the editor
      // sees which fields were updated, then the highlight fades.
      const fieldsToClear = new Set(changedFieldsRef.current);
      changedFieldsRef.current.clear();
      setTimeout(() => {
        // Only clear if the set hasn't been replaced by a new run.
        const current = getDraftingState().updatedFields;
        if (
          current.size === fieldsToClear.size &&
          [...fieldsToClear].every((f) => current.has(f))
        ) {
          setDraftingState({ updatedFields: new Set() });
        }
      }, 2000);
    },
    onError: (error) => {
      console.error("[drafting] Data stream runtime error:", error);
    },
  });

  return runtime;
}
