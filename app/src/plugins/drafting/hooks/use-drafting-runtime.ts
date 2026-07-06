/**
 * Custom runtime hook that connects assistant-ui to the backend
 * drafting endpoint via the Data Stream Protocol.
 *
 * Uses useDataStreamRuntime from @assistant-ui/react-data-stream
 * which sends POST requests to /api/plugins/drafting/chat and
 * consumes UI Message Stream SSE events. The onData callback
 * intercepts data-drafted-fields events to store the raw field
 * values in the Zustand drafting store. The history adapter
 * rehydrates the thread from the backend on mount.
 */

import {
  CompositeAttachmentAdapter,
  ExportedMessageRepository,
  SimpleImageAttachmentAdapter,
  SimpleTextAttachmentAdapter,
} from "@assistant-ui/react";
import { useDataStreamRuntime } from "@assistant-ui/react-data-stream";
import { useMemo } from "react";
import { getSessionMessages } from "@/api/session-messages";
import { getConfig } from "@/config";
import { toThreadMessages } from "../hydrate-transcript";
import { getDraftingState, type PlanStep, setDraftingState } from "../store";

/**
 * Returns an assistant-ui runtime backed by the Data Stream Protocol.
 *
 * The runtime sends POST requests to /api/plugins/drafting/chat
 * and receives UI Message Stream SSE events. assistant-ui handles
 * all event parsing, message rendering, and streaming state. The
 * conversation is scoped to the current editorial session; history
 * and every turn are persisted server side against that session.
 */
export function useDraftingRuntime() {
  // Accept images and common document types as attachments.
  const attachmentAdapter = useMemo(
    () =>
      new CompositeAttachmentAdapter([
        new SimpleImageAttachmentAdapter(),
        new SimpleTextAttachmentAdapter(),
      ]),
    [],
  );

  // Rehydrate the thread from the persisted transcript on mount. The
  // backend records turns during chat, so append is a no-op here.
  const historyAdapter = useMemo(
    () => ({
      async load() {
        const messages = await getSessionMessages();
        return ExportedMessageRepository.fromArray(toThreadMessages(messages));
      },
      async append() {
        // Turns are persisted server side by the chat endpoint.
      },
    }),
    [],
  );

  const runtime = useDataStreamRuntime({
    api: `${getConfig().apiBaseUrl}/plugins/drafting/chat`,
    credentials: "include",
    // Scope the conversation to the current editorial session.
    body: { sessionId: getConfig().sessionId },
    adapters: {
      attachments: attachmentAdapter,
      history: historyAdapter,
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
