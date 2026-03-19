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
  const agent = useMemo(
    () =>
      new HttpAgent({
        url: `${getConfig().apiBaseUrl}/plugins/drafting/chat`,
      }),
    [],
  );

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
      const fields = snapshot.draftedFields as Record<string, DraftedField>;
      setDraftingState({ draftedFields: fields });
    });
    return unsubscribe;
  }, [runtime]);

  return runtime;
}
