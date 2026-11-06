/**
 * Custom runtime hook that connects assistant-ui to the backend
 * drafting endpoint via the Data Stream Protocol.
 *
 * Uses useDataStreamRuntime from @assistant-ui/react-data-stream
 * which sends POST requests to /api/plugins/drafting/chat and
 * consumes UI Message Stream SSE events. The onData callback
 * intercepts data-drafted-fields events to store the raw field
 * values in the Zustand drafting store, and the version-keyed draft
 * (version, context, fields) that useSessionDrafts() merges into the
 * thread-derived list so the rail/save flow see a draft the instant it
 * streams in, without waiting for a reload. The history adapter
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
import { getCsrfHeaders } from "@/api/csrf-token";
import { getSessionMessages } from "@/api/session-messages";
import { getConfig } from "@/config";
import type { ParsedDraftResult } from "../draft-result";
import { toThreadMessages } from "../hydrate-transcript";
import { getDraftingState, type PlanStep, setDraftingState } from "../store";

/**
 * One group's progress, as the drafting node reports it.
 */
interface DraftingActivity {
  id: string;
  type: string;
  data: {
    group: string;
    label: string;
    status: keyof typeof ACTIVITY_STATUS;
    index: number;
    total: number;
  };
}

/**
 * What each status of a group means to the rail.
 */
const ACTIVITY_STATUS = {
  drafting: "in_progress",
  drafted: "done",
  failed: "error",
} as const satisfies Record<string, PlanStep["status"]>;

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
    // Handle custom data-* events from the UI message stream.
    onData: (data) => {
      // Store raw drafted field values directly from the backend, and
      // index the draft by version so useSessionDrafts() can show it
      // before the thread's own tool-call part ever carries the fields.
      // Replaced, not merged, to match openSessionDraft()/the draft card's
      // onOpen(): draftedFields is the fields of activeDraftVersion, and a
      // later draft can carry fewer fields than an earlier one (a template
      // switch, a narrower revision), so merging would leave stale values
      // from a draft no longer open.
      if (data.name === "drafted-fields") {
        const incoming = data.data as ParsedDraftResult;
        const { liveDrafts } = getDraftingState();
        setDraftingState({
          draftedFields: incoming.fields,
          activeDraftVersion: incoming.version as number,
          liveDrafts: { ...liveDrafts, [incoming.version as number]: incoming },
        });
      }
      // The drafting node reports one group at a time, because Neuron's own
      // progress event replaces the activity that shares its id. The rail
      // shows the whole plan, so the groups seen so far are accumulated here
      // in the order the run reached them.
      if (data.name === "workflow-activity") {
        const activity = data.data as DraftingActivity;
        if (activity.type !== "drafting") {
          return;
        }
        const seen = getDraftingState().plan;
        const step: PlanStep = {
          stepId: activity.data.group,
          label: activity.data.label,
          status: ACTIVITY_STATUS[activity.data.status],
        };
        const next = seen.some((s) => s.stepId === step.stepId)
          ? seen.map((s) => (s.stepId === step.stepId ? step : s))
          : [...seen, step];
        setDraftingState({ plan: next });
      }
    },
    onFinish: () => {
      // The progress belongs to the turn that produced it, so a finished turn
      // leaves none of it on the screen. The drafted fields stay visible.
      setDraftingState({ plan: [] });
    },
    onError: (error) => {
      console.error("[drafting] Data stream runtime error:", error);
    },
  });

  return runtime;
}
