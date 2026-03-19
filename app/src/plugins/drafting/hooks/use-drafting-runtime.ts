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
import type { DraftedField } from "../store";
import { getDraftingState, setDraftingState } from "../store";

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

  const agent = useMemo(() => {
    const httpAgent = new HttpAgent({
      url: `${getConfig().apiBaseUrl}/plugins/drafting/chat`,
    });
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

  useEffect(() => {
    const unsubscribe = runtime.thread.subscribe(() => {
      const threadState = runtime.thread.getState();
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

      // The backend sends raw field values (strings or objects). Wrap
      // each into the DraftedField shape the content table expects.
      // If the value is already a DraftedField (has a label property),
      // use it as-is.
      const fields: Record<string, DraftedField> = {};
      for (const [name, val] of Object.entries(raw)) {
        if (
          val !== null &&
          typeof val === "object" &&
          "label" in (val as Record<string, unknown>)
        ) {
          // Already a DraftedField shape.
          fields[name] = val as DraftedField;
        } else {
          // Raw value from the LLM -- wrap it.
          const strVal =
            typeof val === "object" ? JSON.stringify(val) : String(val ?? "");
          fields[name] = {
            label: name
              .replace(/^field_/, "")
              .replace(/_/g, " ")
              .replace(/\b\w/g, (c) => c.toUpperCase()),
            value: strVal,
            type: typeof val === "object" ? "html" : "string",
          };
        }
      }
      setDraftingState({ draftedFields: fields });
    });
    return unsubscribe;
  }, [runtime]);

  return runtime;
}
