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
import { Check, Loader2, PenLine, Wrench, X } from "lucide-react";
import { useEffect, useRef } from "react";
import { parseDraftResult } from "../draft-result";
import { useSavedVersions } from "../saved-versions";
import { useSessionDrafts } from "../session-drafts";
import { setDraftingState } from "../store";
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
    "my-4 flex w-full items-start gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-left";
  const interactive = onClick
    ? " cursor-pointer transition-colors hover:border-gray-300 hover:bg-gray-50"
    : "";

  const body = (
    <>
      {/* Status icon */}
      <div className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center">
        {isRunning && (
          <Loader2 size={16} className="animate-spin text-blue-500" />
        )}
        {isDone && <Check size={16} className="text-green-500" />}
        {isError && <X size={16} className="text-red-500" />}
      </div>

      {/* Content */}
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-1.5">
          <Icon size={14} className="shrink-0 text-gray-400" />
          <span className="text-sm font-medium text-gray-700">{label}</span>
        </div>
        {detail && <p className="mt-0.5 text-xs text-gray-400">{detail}</p>}
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

/**
 * Turns a group id into a readable label for the running state.
 *
 * The backend label only arrives with the result, so while the call runs
 * the id is humanized instead: "field_content_paragraphs" reads as
 * "content paragraphs".
 */
function humanizeGroup(group: string | undefined): string {
  return (group ?? "fields").replace(/^field_/, "").replace(/_/g, " ");
}

/**
 * UI for the draft_group tool call.
 *
 * Every group the agent drafts is one call, so the chat shows the drafting
 * progress group by group. The call that completes the set carries the
 * versioned draft: it renders the draft card and opens the draft in the
 * artifact pane when it was produced in this run. Rehydrated cards mount
 * complete and leave the pane alone.
 */
export const DraftGroupToolUI = makeAssistantToolUI<
  { group?: string },
  DraftGroupResult
>({
  toolName: "draft_group",
  render: ({ args, result, status }) => {
    // Saved state and creation time come from the thread index, so the
    // card stays in step with the preview header and the rail.
    const sessionDrafts = useSessionDrafts();
    const savedVersions = useSavedVersions();
    const draft = result?.draft ? parseDraftResult(result.draft) : null;
    const version = draft?.version ?? null;

    // Open the pane once the draft is versioned during this run.
    const wasRunning = useRef(status.type === "running");
    useEffect(() => {
      if (status.type === "running") {
        wasRunning.current = true;
        return;
      }
      if (wasRunning.current && draft !== null && version !== null) {
        wasRunning.current = false;
        setDraftingState({
          draftedFields: draft.fields,
          activeDraftVersion: version,
          isArtifactCollapsed: false,
        });
      }
    }, [status.type, draft, version]);

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

    const fieldCount = Object.keys(result?.fields ?? {}).length;
    const groupCard = (
      <ToolCallCard
        icon={PenLine}
        label={`Drafted ${label}`}
        detail={`${fieldCount} field${fieldCount === 1 ? "" : "s"}`}
        status={status}
      />
    );
    if (draft === null || Object.keys(draft.fields).length === 0) {
      return groupCard;
    }

    return (
      <>
        {groupCard}
        <DraftCard
          version={version}
          context={draft.context}
          fields={draft.fields}
          isSaved={version !== null && savedVersions.has(version)}
          createdAt={
            sessionDrafts.find((entry) => entry.version === version)
              ?.createdAt ?? null
          }
          onOpen={() =>
            // Show this draft in the pane, expanding it if collapsed.
            setDraftingState({
              draftedFields: draft.fields,
              activeDraftVersion: version,
              isArtifactCollapsed: false,
            })
          }
        />
      </>
    );
  },
});

/**
 * UI for the editorial_event tool call.
 *
 * Editorial events are injected into the transcript by the history adapter
 * so they appear at their chronological position in the thread. This renderer
 * converts the tool-call part into a compact, centered EventChip.
 */
export const EditorialEventToolUI = makeAssistantToolUI<
  { eventType: string; summary: string; at?: string },
  unknown
>({
  toolName: "editorial_event",
  render: ({ args }) => (
    <EventChip eventType={args.eventType} summary={args.summary} at={args.at} />
  ),
});

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
  // Convert snake_case tool name to a readable label (underscores to spaces).
  const label = toolName.replace(/_/g, " ");
  return <ToolCallCard icon={Wrench} label={label} status={status} />;
}
