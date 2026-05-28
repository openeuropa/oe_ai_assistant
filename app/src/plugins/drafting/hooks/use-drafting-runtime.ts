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
import {
  getDraftingState,
  type PlanStep,
  setDraftingState,
  useDraftingSlice,
} from "../store";

// -- Schema types -----------------------------------------------------------

/**
 * JSON Schema for a single field item as produced by
 * EntityJsonSchemaComposer. Each property in the top-level
 * schema maps to one of these.
 */
interface JsonSchemaField {
  type: string;
  items?: JsonSchemaItem;
  maxItems?: number;
  description?: string;
}

/** Per-item schema: either a plain object or a oneOf discriminated union. */
interface JsonSchemaItem {
  type: string;
  properties?: Record<string, JsonSchemaProperty>;
  oneOf?: JsonSchemaVariant[];
  "x-targetType"?: string;
}

/** Leaf property within an item (e.g. value, format, target_id). */
interface JsonSchemaProperty {
  type?: string;
  const?: string;
  enum?: string[];
  format?: string;
  maxLength?: number;
  oneOf?: { type: string }[];
}

/** A oneOf variant representing a paragraph bundle. */
interface JsonSchemaVariant {
  type: string;
  properties?: Record<string, JsonSchemaField>;
}

/**
 * Top-level schema returned by GET /content-schema/{entityTypeId}/{bundle}.
 * Produced by EntityJsonSchemaComposer.
 */
interface ContentTypeSchema {
  type: string;
  properties: Record<string, JsonSchemaField>;
  required?: string[];
}

/**
 * Resolved field metadata used for label and type lookup
 * when transforming LLM output into DraftedField objects.
 */
interface ResolvedField {
  label: string;
  type: string;
  /** Sub-field labels keyed by bundle, for paragraph fields. */
  variants?: Record<string, { label: string; fields: Record<string, string> }>;
}

/** Flattened field lookup keyed by machine name. */
type FieldIndex = Record<string, ResolvedField>;

/**
 * Resolves the simplified field type from a JSON Schema item.
 * Maps JSON Schema shapes to the type strings the UI uses for
 * rendering (e.g. "string", "html", "boolean", "reference").
 */
function resolveFieldType(field: JsonSchemaField): string {
  const items = field.items;
  if (!items) return "string";

  // Inline entity references (paragraphs): oneOf + x-targetType.
  if (items.oneOf && items["x-targetType"]) return "inline_form";

  // Plain entity references: x-targetType without oneOf.
  if (items["x-targetType"]) return "reference";

  // Inspect the item properties to detect formatted text vs scalar.
  const props = items.properties ?? {};
  if (props.value) {
    // Has a 'format' property alongside 'value' -> formatted text.
    if (props.format) return "html";
    // Leaf type from the value property.
    const valueType = props.value.type ?? "string";
    if (valueType === "boolean") return "boolean";
    if (valueType === "integer" || valueType === "number") return "number";
    if (props.value.format === "date" || props.value.format === "date-time")
      return "date";
    if (props.value.enum) return "select";
    return "string";
  }

  // Link fields (uri + title), or other multi-property objects.
  if (props.uri) return "link";

  return "string";
}

/**
 * Extracts the bundle discriminator from a oneOf variant.
 * The composer injects `type.items.properties.target_id.const`
 * as the bundle key (e.g. "text_block", "quote_block").
 */
function variantBundle(variant: JsonSchemaVariant): string {
  const typeProp = variant.properties?.type;
  return typeProp?.items?.properties?.target_id?.const ?? "";
}

/**
 * Builds variant metadata (bundle label + sub-field labels)
 * from the oneOf array of a paragraph reference field.
 */
function buildVariants(
  variants: JsonSchemaVariant[],
): Record<string, { label: string; fields: Record<string, string> }> {
  const result: Record<
    string,
    { label: string; fields: Record<string, string> }
  > = {};
  for (const variant of variants) {
    const bundle = variantBundle(variant);
    if (!bundle) continue;
    const fields: Record<string, string> = {};
    for (const [name, prop] of Object.entries(variant.properties ?? {})) {
      // Skip system fields (type, status, parent_*, behavior_settings).
      if (
        name === "type" ||
        name === "status" ||
        name.startsWith("parent_") ||
        name === "behavior_settings"
      )
        continue;
      fields[name] = (prop as JsonSchemaField).description ?? name;
    }
    result[bundle] = { label: bundle, fields };
  }
  return result;
}

/**
 * Fetches the JSON Schema for a content type and returns a flat
 * field index keyed by machine name.
 */
async function fetchFieldIndex(
  apiBaseUrl: string,
  entityTypeId: string,
  bundle: string,
): Promise<FieldIndex> {
  try {
    const url = `${apiBaseUrl}/content-schema/${entityTypeId}/${bundle}`;
    const res = await fetch(url, { credentials: "include" });
    if (!res.ok) return {};
    const schema = (await res.json()) as ContentTypeSchema;
    const index: FieldIndex = {};
    for (const [name, field] of Object.entries(schema.properties ?? {})) {
      const resolved: ResolvedField = {
        label: field.description ?? labelFromName(name),
        type: resolveFieldType(field),
      };
      // For paragraph fields, extract per-bundle sub-field labels.
      const variants = field.items?.oneOf;
      if (variants) {
        resolved.variants = buildVariants(variants);
      }
      index[name] = resolved;
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
 * DraftedInlineEntity shape, using the schema's variant metadata
 * to resolve sub-field labels.
 */
function toInlineEntity(
  raw: Record<string, unknown>,
  schemaField?: ResolvedField,
): DraftedInlineEntity {
  // Determine bundle from the raw data or fall back to the first variant.
  const variants = schemaField?.variants ?? {};
  const rawBundle =
    (raw.bundle as string) ??
    (raw.type as string) ??
    Object.keys(variants)[0] ??
    "";
  const variantDef = variants[rawBundle];
  const subFieldLabels = variantDef?.fields ?? {};

  const fields: Record<string, DraftedSubField> = {};
  for (const [key, val] of Object.entries(raw)) {
    if (typeof val === "string" || typeof val === "number") {
      fields[key] = {
        label: subFieldLabels[key] ?? labelFromName(key),
        value: String(val),
      };
    }
  }
  return {
    bundle: rawBundle,
    bundleLabel: variantDef?.label ?? "",
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
    const resolved = fieldIndex[name];
    const label = resolved?.label ?? labelFromName(name);
    const fieldType = resolved?.type ?? "string";

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
          toInlineEntity(item as Record<string, unknown>, resolved),
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

  // Read the persisted threadId so multi-turn conversations
  // share server-side history across requests.
  const { threadId } = useDraftingSlice();

  const runtime = useDataStreamRuntime({
    api: `${getConfig().apiBaseUrl}/plugins/drafting/chat`,
    credentials: "include",
    // Send bundle, entityTypeId, and threadId in the request body.
    body: { bundle, entityTypeId, threadId },
    adapters: {
      attachments: attachmentAdapter,
    },
    // Handle custom data-* events from the UI message stream.
    onData: (data) => {
      if (data.name === "drafted-fields") {
        handleDraftedFields(data.data as Record<string, unknown>);
      }
      // Capture the threadId from the backend and persist it
      // so subsequent requests include it for history continuity.
      if (data.name === "thread-id") {
        const incoming = (data.data as { threadId?: string }).threadId;
        if (incoming) {
          setDraftingState({ threadId: incoming });
        }
      }
      // Handle orchestration plan updates. The backend emits the
      // full plan array each time a step status changes.
      if (data.name === "plan") {
        setDraftingState({ plan: data.data as PlanStep[] });
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
