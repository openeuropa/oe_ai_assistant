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
 * streams in, without waiting for a reload. It also follows the run that
 * writes the draft, from the groups it names before it starts to the group
 * that stops it, which is what the draft card reports while the run works.
 * The history adapter rehydrates the thread from the backend on mount.
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
import type { DraftContext, DraftGroupProgress } from "../draft-result";
import { toThreadMessages } from "../hydrate-transcript";
import {
  type DraftRunProgress,
  getDraftingState,
  type LiveDraft,
  setDraftingState,
} from "../store";

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
 * What each status of a group means to the card.
 */
const ACTIVITY_STATUS = {
  drafting: "drafting",
  drafted: "drafted",
  failed: "failed",
} as const satisfies Record<string, DraftGroupProgress["status"]>;

/**
 * Applies a change to every run the turn in flight is drafting.
 *
 * A turn settles one drafting call, and the card of that call reads the run
 * under its own id. The model can ask twice in one turn, and both calls then
 * wait for the same draft, so the change reaches all of them.
 */
function updateActiveRuns(
  change: (run: DraftRunProgress) => DraftRunProgress,
): void {
  const { runs, activeCallIds } = getDraftingState();
  const next = { ...runs };
  for (const callId of activeCallIds) {
    const run = next[callId];
    if (run) {
      next[callId] = change(run);
    }
  }
  setDraftingState({ runs: next });
}

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
      // The run names its groups before it drafts the first one, so the card
      // of the call that asked shows every row from the start.
      if (data.name === "draft-started") {
        const started = data.data as {
          version: number;
          callIds: string[];
          context: DraftContext;
          groups: DraftGroupProgress[];
        };
        const { runs } = getDraftingState();
        const next = { ...runs };
        for (const callId of started.callIds) {
          next[callId] = {
            version: started.version,
            status: "running",
            groups: started.groups,
            context: started.context,
          };
        }
        setDraftingState({ runs: next, activeCallIds: started.callIds });
      }

      // Store raw drafted field values directly from the backend, and
      // index the draft by version so useSessionDrafts() can show it
      // before the thread's own tool-call part ever carries the fields.
      // Replaced, not merged, to match openSessionDraft()/the draft card's
      // onOpen(): draftedFields is the fields of activeDraftVersion, and a
      // later draft can carry fewer fields than an earlier one (a template
      // switch, a narrower revision), so merging would leave stale values
      // from a draft no longer open.
      if (data.name === "drafted-fields") {
        const incoming = data.data as LiveDraft;
        const { liveDrafts } = getDraftingState();
        setDraftingState({
          draftedFields: incoming.fields,
          activeDraftVersion: incoming.version as number,
          liveDrafts: { ...liveDrafts, [incoming.version as number]: incoming },
          // The editor asked for this draft, so the pane opens on it. A pane
          // the editor collapsed earlier holds an older draft, and leaving it
          // shut hides the one they waited for.
          isArtifactCollapsed: false,
        });
        updateActiveRuns((run) => ({ ...run, status: "drafted" }));
      }

      // The drafting node reports one group at a time, because Neuron's own
      // progress event replaces the activity that shares its id.
      if (data.name === "workflow-activity") {
        const activity = data.data as DraftingActivity;
        if (activity.type !== "drafting") {
          return;
        }
        const status = ACTIVITY_STATUS[activity.data.status];
        updateActiveRuns((run) => ({
          ...run,
          groups: run.groups.map((group) =>
            group.id === activity.data.group ? { ...group, status } : group,
          ),
        }));
      }

      // A failure ends the run, and the message belongs to the group that
      // stopped it. A failure that reached no group is the run's own.
      if (data.name === "draft-failed") {
        const failure = data.data as {
          group: string;
          error: string;
        };
        updateActiveRuns((run) => ({
          ...run,
          status: "failed",
          error: failure.group === "" ? failure.error : undefined,
          groups: run.groups.map((group) =>
            group.id === failure.group
              ? { ...group, status: "failed", error: failure.error }
              : group,
          ),
        }));
      }
    },
    onFinish: () => {
      // The run is over, so later progress events belong to no call. The runs
      // themselves stay: each card reads the one its own call reported.
      setDraftingState({ activeCallIds: [] });
    },
    onError: (error) => {
      console.error("[drafting] Data stream runtime error:", error);
    },
  });

  return runtime;
}
