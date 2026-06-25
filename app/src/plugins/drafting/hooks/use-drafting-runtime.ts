/**
 * Custom runtime hook that connects assistant-ui to the backend
 * drafting endpoint via the Data Stream Protocol.
 *
 * Uses useDataStreamRuntime from @assistant-ui/react-data-stream
 * which sends POST requests to /api/plugins/drafting/chat and
 * consumes UI Message Stream SSE events. The onData callback
 * intercepts data-drafted-fields events to store the raw field
 * values in the Zustand drafting store.
 */

import {
  CompositeAttachmentAdapter,
  SimpleImageAttachmentAdapter,
  SimpleTextAttachmentAdapter,
} from "@assistant-ui/react";
import { useDataStreamRuntime } from "@assistant-ui/react-data-stream";
import { useMemo } from "react";
import { getConfig } from "@/config";
import {
  getDraftingState,
  type PlanStep,
  setDraftingState,
  useDraftingSlice,
} from "../store";
import type { DraftingPluginConfig } from "../types";

/**
 * Returns an assistant-ui runtime backed by the Data Stream Protocol.
 *
 * The runtime sends POST requests to /api/plugins/drafting/chat
 * and receives UI Message Stream SSE events. assistant-ui handles
 * all event parsing, message rendering, and streaming state. The
 * onData callback intercepts custom events to update the Zustand
 * drafting store.
 */
export function useDraftingRuntime() {
  // Read bundle and entity type from the host page's plugin config.
  const draftingConfig =
    (getConfig().pluginConfig.drafting as DraftingPluginConfig | undefined) ??
    {};
  const bundle = draftingConfig.bundle ?? "";
  const entityTypeId = draftingConfig.entityTypeId ?? "node";

  // Accept images and common document types as attachments.
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
    body: () => ({ bundle, entityTypeId, threadId }),
    adapters: {
      attachments: attachmentAdapter,
    },
    // Handle custom data-* events from the UI message stream.
    onData: (data) => {
      // Store raw drafted field values directly from the backend.
      if (data.name === "drafted-fields") {
        const incoming = data.data as Record<string, unknown>;
        const existing = getDraftingState().draftedFields;
        setDraftingState({
          draftedFields: { ...existing, ...incoming },
        });
      }
      // Capture the threadId for conversation continuity.
      if (data.name === "thread-id") {
        const incoming = (data.data as { threadId?: string }).threadId;
        if (incoming) {
          setDraftingState({ threadId: incoming });
        }
      }
      // Handle orchestration plan updates.
      if (data.name === "plan") {
        setDraftingState({ plan: data.data as PlanStep[] });
      }
    },
    onFinish: () => {
      // Clear plan when the run finishes so it doesn't linger.
      // The drafted fields stay visible.
    },
    onError: (error) => {
      console.error("[drafting] Data stream runtime error:", error);
    },
  });

  return runtime;
}
