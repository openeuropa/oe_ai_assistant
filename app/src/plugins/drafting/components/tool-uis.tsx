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
import type { DraftRunStatus } from "../draft-result";
import { parseDraftResult } from "../draft-result";
import { useSavedVersions } from "../saved-versions";
import { useSessionDrafts } from "../session-drafts";
import { setDraftingState, useDraftingSlice } from "../store";
import { toolLabel } from "../tool-labels";
import { DraftCard } from "./draft-card";
import { EventChip } from "./event-chip";

/** Shared wrapper for tool call cards in the chat. */
export function ToolCallCard({
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

/**
 * What a run's own status means to the tool call card.
 *
 * assistant-ui settles the call as complete whatever the run reported: a
 * failure travels inside the result rather than as a tool error. So the card
 * reads the status of the run instead of the status of the call.
 */
const TOOL_STATUS = {
  running: "running",
  drafted: "complete",
  failed: "incomplete",
} as const satisfies Record<DraftRunStatus, string>;

/** UI for the drafting_draft_content tool call. */
export const DraftContentToolUI = makeAssistantToolUI<
  Record<string, never>,
  Record<string, unknown>
>({
  toolName: "drafting_draft_content",
  render: ({ result, status, toolCallId, toolName }) => {
    // Saved state and creation time come from the thread index, so the
    // card stays in step with the preview header and the rail.
    const sessionDrafts = useSessionDrafts();
    const savedVersions = useSavedVersions();
    // The run streams its progress under the id of the call that asked for
    // the draft. A reload reports no run, and the result then carries the
    // record the backend joins onto it.
    const run = useDraftingSlice().runs[toolCallId];
    const parsed = parseDraftResult(result);

    const version = run?.version ?? parsed.version;
    const runStatus = run?.status ?? parsed.status;
    const groups = run?.groups ?? parsed.groups;
    const context = run?.context ?? parsed.context;
    const error =
      run?.error ?? (parsed.error === "" ? undefined : parsed.error);

    // The fields never travel with the call's own result, so they come from
    // the drafts index, which merges the live stream with the thread.
    const draft =
      version === null
        ? undefined
        : sessionDrafts.find((entry) => entry.version === version);

    const card = (
      <ToolCallCard
        icon={PenLine}
        label={toolLabel(toolName)}
        // A run with no version draws no draft card, and the card is where
        // a failure names its reason. The editor would read a failed call
        // and no reason for it. So the detail line carries the error.
        detail={version === null ? error : undefined}
        // A failed run still answers its call, so the call itself reads as
        // complete. A card that took the status of the call would show the
        // editor a draft that landed. So the status comes from the run, and
        // only a call with no run of its own keeps its status.
        status={
          version === null && runStatus !== "failed"
            ? status
            : { type: TOOL_STATUS[runStatus] }
        }
      />
    );

    // Until the run names its version there is nothing to draw a card from,
    // so the call shows as the plain tool card it is.
    if (version === null) {
      return card;
    }

    return (
      <>
        {card}
        <DraftCard
          version={version}
          context={context}
          groups={groups}
          status={runStatus}
          error={error}
          isSaved={savedVersions.has(version)}
          createdAt={draft?.createdAt ?? null}
          onOpen={() =>
            // Show this draft in the pane, expanding it if collapsed.
            setDraftingState({
              draftedFields: draft?.fields ?? {},
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
 * ToolCallCard with a Wrench icon and the label the host declares for it.
 */
export function ToolFallbackCard({
  toolName,
  status,
}: ToolCallMessagePartProps) {
  // The props name the tool and carry no label, so a card built from the
  // name alone would show the editor drafting_get_history. The host
  // declares a label for every tool, and this card reads it.
  return (
    <ToolCallCard icon={Wrench} label={toolLabel(toolName)} status={status} />
  );
}
