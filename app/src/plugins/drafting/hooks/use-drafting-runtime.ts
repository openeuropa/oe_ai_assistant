/**
 * Custom runtime hook that connects assistant-ui to the backend
 * drafting endpoint via the UI message stream.
 *
 * Uses useDataStreamRuntime from @assistant-ui/react-data-stream
 * which sends POST requests to /api/plugins/drafting/chat and
 * consumes the SSE events. Drafting progress and the versioned
 * draft travel as tool parts, so the registered tool UIs read them
 * from the thread. The events of the agent run arrive as transient
 * data parts and go to the console as they happen, as errors when
 * they report a failure. The history
 * adapter rehydrates the thread from the backend on mount.
 */

import {
  CompositeAttachmentAdapter,
  ExportedMessageRepository,
  SimpleImageAttachmentAdapter,
  SimpleTextAttachmentAdapter,
} from "@assistant-ui/react";
import { useDataStreamRuntime } from "@assistant-ui/react-data-stream";
import { useMemo } from "react";
import { getCsrfHeaders } from "@/api/csrf-token";
import { getSessionMessages } from "@/api/session-messages";
import type { AgentEventData } from "@/api/sse-types";
import { getConfig } from "@/config";
import { toThreadMessages } from "../hydrate-transcript";

/**
 * Returns an assistant-ui runtime backed by the UI message stream.
 *
 * The runtime sends POST requests to /api/plugins/drafting/chat
 * and receives the SSE events. assistant-ui handles all event
 * parsing, message rendering, and streaming state. The
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
        const messages = await getSessionMessages("drafting");
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
    // The CMS requires the CSRF token on every plugin request.
    headers: getCsrfHeaders,
    // Scope the conversation to the current editorial session.
    body: { sessionId: getConfig().sessionId },
    adapters: {
      attachments: attachmentAdapter,
      history: historyAdapter,
    },
    // Every event of the agent run, logged the moment it is emitted.
    // TODO: temporary; the payload exposes prompts, answers and tool
    // results, so this will be gated behind a dev-only configuration.
    onData: (data) => {
      if (data.name === "agent-event") {
        const event = data.data as AgentEventData;
        const line = `[agent] ${event.agent}: ${event.summary}`;
        // A rejected answer or a failed run is an error; the rest is trace.
        if (event.level === "error") {
          console.error(line, event.payload);
        } else {
          console.log(line, event.payload);
        }
      }
    },
    onError: (error) => {
      console.error("[drafting] Data stream runtime error:", error);
    },
  });

  return runtime;
}
