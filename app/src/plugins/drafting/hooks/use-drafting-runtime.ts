/**
 * Custom runtime hook that connects assistant-ui to our AG-UI
 * backend endpoint via the @assistant-ui/react-ag-ui adapter.
 *
 * Creates an HttpAgent pointing at our /api/plugins/drafting/chat
 * endpoint and returns an AssistantRuntime. Also subscribes to
 * AG-UI state snapshots to populate the drafted fields store.
 */

import { HttpAgent } from "@ag-ui/client";
import {
  CompositeAttachmentAdapter,
  SimpleImageAttachmentAdapter,
  SimpleTextAttachmentAdapter,
} from "@assistant-ui/react";
import { useAgUiRuntime } from "@assistant-ui/react-ag-ui";
import { useEffect, useMemo, useRef } from "react";
import { getConfig } from "@/config";
import { createSmoothingMiddleware } from "@/lib/event-smoothing";
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
 * Returns an assistant-ui runtime backed by our AG-UI endpoint.
 *
 * The HttpAgent sends POST requests to /api/plugins/drafting/chat
 * and receives AG-UI SSE events. assistant-ui handles all event
 * parsing, message rendering, and streaming state. We subscribe
 * to the runtime's state to pick up STATE_SNAPSHOT events that
 * contain drafted field values.
 */
export function useDraftingRuntime() {
  // Read bundle and entity type from the host page's plugin config.
  const draftingConfig = getConfig().pluginConfig["drafting"] ?? {};
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

  const agent = useMemo(() => {
    const httpAgent = new HttpAgent({
      url: `${getConfig().apiBaseUrl}/plugins/drafting/chat`,
    });

    // Apply event smoothing middleware. When SSE events arrive in
    // bursts (common with PHP + reverse proxy stacks), this queues
    // them and releases one at a time at a controlled pace.
    // The cast works around duplicate-rxjs type conflicts between
    // @ag-ui/client's bundled rxjs and the top-level rxjs.
    // biome-ignore lint/suspicious/noExplicitAny: rxjs version mismatch requires cast
    httpAgent.use(createSmoothingMiddleware(getConfig().eventSmoothing) as any);

    // Wrap runAgent to inject forwardedProps with the content type
    // context on every chat request. The backend reads these to load
    // the correct schema for tool calls.
    const originalRun = httpAgent.runAgent.bind(httpAgent);
    httpAgent.runAgent = (input, ...rest) =>
      originalRun(
        {
          ...input,
          forwardedProps: {
            ...(input?.forwardedProps ?? {}),
            bundle,
            entityTypeId,
          },
        },
        ...rest,
      );
    return httpAgent;
  }, [bundle, entityTypeId]);

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

  const runtime = useAgUiRuntime({
    agent,
    adapters: {
      attachments: attachmentAdapter,
      threadList: {
        threadId: getDraftingState().threadId ?? undefined,
      },
    },
    onError: (error) => {
      console.error("[drafting] AG-UI runtime error:", error);
    },
  });

  // Track the last snapshot we processed to avoid infinite loops.
  // Without this guard, setDraftingState triggers a re-render which
  // triggers the subscribe callback again.
  const lastSnapshotRef = useRef<string | null>(null);

  // Track per-field serialized values to detect which field changed.
  const lastFieldValuesRef = useRef<Record<string, string>>({});

  useEffect(() => {
    const unsubscribe = runtime.thread.subscribe(() => {
      const threadState = runtime.thread.getState();

      // Detect run completion to clear the streaming indicator.
      const status = (threadState as Record<string, unknown>).status as
        | string
        | undefined;
      if (status !== "running" && status !== "streaming") {
        if (getDraftingState().streamingFieldName !== null) {
          setDraftingState({ streamingFieldName: null });
        }
      }

      const snapshot = (threadState as Record<string, unknown>).state as
        | Record<string, unknown>
        | undefined;

      if (
        !snapshot ||
        typeof snapshot !== "object" ||
        !("draftedFields" in snapshot)
      ) {
        return;
      }

      // Only update if the snapshot actually changed.
      const serialized = JSON.stringify(snapshot.draftedFields);
      if (serialized === lastSnapshotRef.current) {
        return;
      }

      lastSnapshotRef.current = serialized;
      const raw = snapshot.draftedFields as Record<string, unknown>;

      // Transform raw LLM values into DraftedField objects using the
      // content type schema for label, type, and inline entity resolution.
      const fields = transformFields(raw, fieldIndexRef.current);

      // Detect which field changed by comparing per-field serialized
      // values against the previous snapshot.
      let changedField: string | null = null;
      const currentFieldValues: Record<string, string> = {};
      for (const [name, field] of Object.entries(fields)) {
        const fieldSerialized = JSON.stringify(field);
        currentFieldValues[name] = fieldSerialized;
        if (fieldSerialized !== lastFieldValuesRef.current[name]) {
          changedField = name;
        }
      }
      lastFieldValuesRef.current = currentFieldValues;

      setDraftingState({
        draftedFields: fields,
        streamingFieldName: changedField,
      });
    });
    return unsubscribe;
  }, [runtime]);

  return runtime;
}
