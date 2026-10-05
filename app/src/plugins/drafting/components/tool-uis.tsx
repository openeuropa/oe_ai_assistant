/**
 * Tool call UI components for the drafting plugin.
 *
 * Registers custom renderers for the agent's tool calls so they
 * appear inline in the chat with status indicators, similar to
 * how Claude Code shows tool invocations. Each tool gets a
 * distinct visual treatment with a loading state while running
 * and a result summary when complete.
 */

import type { ToolCallMessagePartProps } from "@assistant-ui/react";
import { makeAssistantToolUI } from "@assistant-ui/react";
import {
  Check,
  FileJson,
  History,
  Loader2,
  Pencil,
  PenLine,
  Wrench,
  X,
} from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { eventBus } from "@/lib/events";
import { type ParsedDraftResult, parseDraftResult } from "../draft-result";
import { useSavedVersions } from "../saved-versions";
import { openSessionDraft, useSessionDraft } from "../session-drafts";
import type { SaveDraftResult } from "../types";
import { DraftCard } from "./draft-card";
import { EventChip } from "./event-chip";

/** Shared wrapper for tool call cards in the chat. */
function ToolCallCard({
  icon: Icon,
  label,
  detail,
  status,
  onClick,
}: {
  icon: typeof PenLine;
  label: string;
  detail?: string;
  status: { type: string };
  /** When set, the card becomes a button that runs this on click. */
  onClick?: () => void;
}) {
  const isRunning = status.type === "running";
  const isError = status.type === "incomplete" || status.type === "error";
  const isDone = status.type === "complete";

  const base =
    "my-2 flex w-full items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-left";
  const interactive = onClick
    ? " cursor-pointer transition-colors hover:border-gray-300 hover:bg-gray-50"
    : "";

  const body = (
    <>
      {/* Status icon */}
      <div className="flex h-5 w-5 shrink-0 items-center justify-center">
        {isRunning && (
          <Loader2 size={16} className="animate-spin text-blue-500" />
        )}
        {isDone && <Check size={16} className="text-green-500" />}
        {isError && <X size={16} className="text-red-500" />}
      </div>

      {/* Label and detail share one line; a long detail truncates. */}
      <div className="flex min-w-0 flex-1 items-center gap-2">
        <Icon size={14} className="shrink-0 text-gray-400" />
        <span className="shrink-0 text-sm font-medium text-gray-700">
          {label}
        </span>
        {detail && (
          <span className="truncate text-xs text-gray-400">{detail}</span>
        )}
      </div>
    </>
  );

  // A clickable card gets button semantics and a hover affordance.
  if (onClick) {
    return (
      <button type="button" className={base + interactive} onClick={onClick}>
        {body}
      </button>
    );
  }
  return <div className={base}>{body}</div>;
}

/** The result of one draft_group call, as the backend returns it. */
interface DraftGroupResult {
  group?: string;
  /** The human-readable group label. */
  label?: string;
  /** The drafted values of this group, keyed by field name. */
  fields?: Record<string, unknown>;
  /** The versioned draft, present on the call that completed the set. */
  draft?: unknown;
  /** The failure the tool reported, when the group could not be drafted. */
  error?: string;
}

/** The result of one revise_draft call, as the backend returns it. */
interface ReviseDraftResult {
  /** The ids of the groups drafted again. */
  revised?: string[];
  /** The version the revision started from. */
  revisionOf?: number;
  /** The versioned draft the revision produced. */
  draft?: unknown;
  /** The failure the tool reported, when nothing could be revised. */
  error?: string;
}

/**
 * Turns a group id into a readable label.
 *
 * The backend label only arrives with the result, so while a call runs the
 * id is humanized instead: "field_content_paragraphs" reads as "content
 * paragraphs".
 */
function humanizeGroup(group: string | undefined): string {
  return (group ?? "fields").replace(/^field_/, "").replace(/_/g, " ");
}

/**
 * Describes what one group call produced.
 *
 * A reference group holds a single field whose items are the entities that
 * were drafted, so counting its fields would always say one. Everywhere
 * else the fields themselves are what the editor drafted.
 */
function draftedDetail(result: DraftGroupResult): string | undefined {
  if (!result.fields) {
    return undefined;
  }
  const items = result.fields[result.group ?? ""];
  return Array.isArray(items)
    ? countLabel(items.length, "item")
    : countLabel(Object.keys(result.fields).length, "field");
}

/** Counts a thing for a card detail, e.g. "2 drafts". */
function countLabel(count: number, noun: string): string {
  return `${count} ${noun}${count === 1 ? "" : "s"}`;
}

/** Joins several group ids into one readable label. */
function humanizeGroups(groups: string[] | undefined): string {
  return groups?.length ? groups.map(humanizeGroup).join(", ") : "the draft";
}

/**
 * Opens the pane on a draft the moment the run produces it.
 *
 * A rehydrated card mounts complete and leaves the pane alone; only a call
 * that was still running when it mounted opens what it produced.
 */
function useProducedDraft(
  status: { type: string },
  draft: ParsedDraftResult | null,
) {
  const wasRunning = useRef(status.type === "running");
  useEffect(() => {
    if (status.type === "running") {
      wasRunning.current = true;
      return;
    }
    if (wasRunning.current && draft !== null) {
      wasRunning.current = false;
      openSessionDraft(draft);
    }
  }, [status.type, draft]);
}

/** Parses the draft a tool result carries, once per result. */
function useParsedDraft(raw: unknown): ParsedDraftResult | null {
  return useMemo(() => (raw ? parseDraftResult(raw) : null), [raw]);
}

/** Renders the versioned draft a tool call produced. */
function ProducedDraftCard({ draft }: { draft: ParsedDraftResult }) {
  // The name, saved state and creation time come from the thread index, so
  // the card stays in step with the preview header and the rail.
  const entry = useSessionDraft(draft.version);
  const savedVersions = useSavedVersions();

  return (
    <DraftCard
      name={entry?.name ?? "Draft"}
      context={draft.context}
      fields={draft.fields}
      isSaved={savedVersions.has(draft.version)}
      createdAt={entry?.createdAt ?? null}
      onOpen={() => openSessionDraft(draft)}
    />
  );
}

/**
 * UI for the draft_group tool call.
 *
 * Every group the agent drafts is one call, so the chat shows the drafting
 * progress group by group. The call that completes the set carries the
 * versioned draft and renders the draft card.
 */
export const DraftGroupToolUI = makeAssistantToolUI<
  { group?: string },
  DraftGroupResult
>({
  toolName: "draft_group",
  render: ({ args, result, status }) => {
    const draft = useParsedDraft(result?.draft);
    useProducedDraft(status, draft);

    const label = result?.label ?? humanizeGroup(args?.group);
    if (status.type !== "complete") {
      return (
        <ToolCallCard
          icon={PenLine}
          label={`Drafting ${label}`}
          status={status}
        />
      );
    }
    if (result?.error) {
      return (
        <ToolCallCard
          icon={PenLine}
          label={`Drafting ${label}`}
          detail={result.error}
          status={{ type: "error" }}
        />
      );
    }

    return (
      <>
        <ToolCallCard
          icon={PenLine}
          label={`Drafted ${label}`}
          detail={result ? draftedDetail(result) : undefined}
          status={status}
        />
        {draft !== null && Object.keys(draft.fields).length > 0 && (
          <ProducedDraftCard draft={draft} />
        )}
      </>
    );
  },
});

/**
 * UI for the revise_draft tool call.
 *
 * A revision drafts the named groups again from the draft it starts from
 * and carries the rest over, so one call produces one new version and one
 * card.
 */
export const ReviseDraftToolUI = makeAssistantToolUI<
  { groups?: string[]; version?: number },
  ReviseDraftResult
>({
  toolName: "revise_draft",
  render: ({ args, result, status }) => {
    const draft = useParsedDraft(result?.draft);
    useProducedDraft(status, draft);

    const label = humanizeGroups(result?.revised ?? args?.groups);
    if (status.type !== "complete") {
      return (
        <ToolCallCard
          icon={Pencil}
          label={`Revising ${label}`}
          status={status}
        />
      );
    }
    if (result?.error || draft === null) {
      return (
        <ToolCallCard
          icon={Pencil}
          label={`Revising ${label}`}
          detail={result?.error}
          status={{ type: "error" }}
        />
      );
    }

    return (
      <>
        <ToolCallCard
          icon={Pencil}
          label={`Revised ${label}`}
          detail={
            result?.revisionOf !== undefined
              ? `From draft version ${result.revisionOf}`
              : undefined
          }
          status={status}
        />
        <ProducedDraftCard draft={draft} />
      </>
    );
  },
});

/**
 * UI for the get_content_schema tool call.
 *
 * The agent reads the field groups it may draft; the card says how many
 * came back.
 */
export const GetContentSchemaToolUI = makeAssistantToolUI<
  Record<string, never>,
  unknown[]
>({
  toolName: "get_content_schema",
  render: ({ result, status }) => (
    <ToolCallCard
      icon={FileJson}
      label={
        status.type === "complete"
          ? "Got content schema"
          : "Getting content schema"
      }
      detail={
        Array.isArray(result) ? countLabel(result.length, "group") : undefined
      }
      status={status}
    />
  ),
});

/**
 * UI for the get_draft_history tool call.
 *
 * The agent looks up the drafts of the session, which is how it answers
 * questions about earlier versions and resolves the one to revise.
 */
export const GetDraftHistoryToolUI = makeAssistantToolUI<
  Record<string, never>,
  { drafts?: unknown[] }
>({
  toolName: "get_draft_history",
  render: ({ result, status }) => (
    <ToolCallCard
      icon={History}
      label={
        status.type === "complete"
          ? "Got draft history"
          : "Getting draft history"
      }
      detail={
        result?.drafts ? countLabel(result.drafts.length, "draft") : undefined
      }
      status={status}
    />
  ),
});

/**
 * UI for the save_draft tool call.
 *
 * A save is gated, so the call reaches the thread with no result and waits
 * there until the editor answers it. The decision travels on the event bus,
 * because a tool UI cannot reach the thread runtime that has to reload the
 * conversation afterwards. A reload of the page shows the same buttons, since
 * the waiting call is part of the stored conversation.
 */
export const SaveDraftToolUI = makeAssistantToolUI<
  { version?: number },
  SaveDraftResult
>({
  toolName: "save_draft",
  render: ({ args, result, toolCallId }) => {
    if (result?.nodeId !== undefined) {
      return (
        <EventChip
          eventType="save"
          summary={`${result.name ?? `Draft ${result.version}`} saved as unpublished revision`}
        />
      );
    }
    if (result?.error !== undefined) {
      return <EventChip eventType="error" summary={result.error} />;
    }

    // No result at all means the call has not run, which for a gated tool
    // means it is waiting for the editor. The status is not what decides it:
    // the same call reads as complete once its turn ends, and the question
    // still has to be asked after a reload.
    return <SaveApprovalPrompt callId={toolCallId} version={args.version} />;
  },
});

/**
 * The buttons that answer a waiting save.
 *
 * Neither answer asks for anything else. A refusal is recorded on the call, so
 * the model learns the save did not happen without the editor explaining it.
 */
function SaveApprovalPrompt({
  callId,
  version,
}: {
  callId: string;
  version?: number;
}) {
  const [answered, setAnswered] = useState(false);
  const decide = (decision: "approve" | "reject") => {
    setAnswered(true);
    eventBus.emit("approval:decide", { callId, decision });
  };

  return (
    <div className="my-2 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm">
      <p className="mb-2 text-gray-900">
        {version === undefined
          ? "Save this draft to the content item?"
          : `Save Draft ${version} to the content item?`}{" "}
        It is written as an unpublished revision.
      </p>
      <div className="flex gap-2">
        <Button
          size="sm"
          className="cursor-pointer"
          disabled={answered}
          onClick={() => decide("approve")}
        >
          Save it
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="cursor-pointer"
          disabled={answered}
          onClick={() => decide("reject")}
        >
          Not yet
        </Button>
      </div>
    </div>
  );
}

/**
 * Fallback renderer for any tool call not registered with makeAssistantToolUI.
 *
 * Receives ToolCallMessagePartProps from assistant-ui (toolName, args, result,
 * argsText, status, addResult, resume, type, toolCallId). Renders a generic
 * ToolCallCard with a Wrench icon and the tool name humanized for display.
 */
export function ToolFallbackCard({
  toolName,
  status,
}: ToolCallMessagePartProps) {
  // Convert snake_case tool name to a readable label: underscores become
  // spaces and the first word is capitalised.
  const words = toolName.replace(/_/g, " ");
  const label = words.charAt(0).toUpperCase() + words.slice(1);
  return <ToolCallCard icon={Wrench} label={label} status={status} />;
}
