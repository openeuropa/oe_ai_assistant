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
import { parseDraftResult } from "../draft-result";
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

/** UI for the draft_content tool call. */
export const DraftContentToolUI = makeAssistantToolUI<
  { fields: Record<string, unknown> },
  Record<string, unknown>
>({
  toolName: "draft_content",
  render: ({ args, result, status }) => {
    // While running or on error, show the generic tool card with status.
    if (status.type !== "complete") {
      const fieldCount = Object.keys(args?.fields ?? {}).length;
      return (
        <ToolCallCard
          icon={PenLine}
          label="Drafting content"
          detail={
            fieldCount > 0
              ? `${fieldCount} field${fieldCount > 1 ? "s" : ""}`
              : undefined
          }
          status={status}
        />
      );
    }

    // On completion, parse the result (versioned object on the live path,
    // or fall back to args.fields when result is empty on a rehydrated trace).
    // This choice only picks the data source; success itself is signalled by
    // the complete status checked above.
    const raw =
      result && Object.keys(result).length > 0 ? result : (args?.fields ?? {});
    const parsed = parseDraftResult(raw);
    const fields = parsed.fields;

    // A completed call that yielded no fields has nothing to open, so show
    // the plain status card instead of an openable draft card.
    if (Object.keys(fields).length === 0) {
      return (
        <ToolCallCard icon={PenLine} label="Drafting content" status={status} />
      );
    }

    return (
      <DraftCard
        version={parsed.version}
        context={parsed.context}
        fields={fields}
        onOpen={() =>
          // Show this draft in the pane, expanding it if collapsed.
          setDraftingState({
            draftedFields: fields,
            activeDraftVersion: parsed.version,
            isArtifactCollapsed: false,
          })
        }
      />
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
