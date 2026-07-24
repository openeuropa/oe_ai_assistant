/**
 * Tool call UI components for the drafting plugin.
 *
 * Registers custom renderers for the agent's tool calls so they
 * appear inline in the chat with status indicators, similar to
 * how Claude Code shows tool invocations. Each tool gets a
 * distinct visual treatment with a loading state while running
 * and a result summary when complete.
 */

import { makeAssistantToolUI } from "@assistant-ui/react";
import { Check, Loader2, PenLine, RefreshCw, Save, X } from "lucide-react";
import { setDraftingState } from "../store";

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
    // The drafted fields are the tool result; on a rehydrated trace they also
    // sit in args.fields. When present, clicking the card shows them again.
    const fields = result ?? args?.fields ?? {};
    const fieldCount = Object.keys(fields).length;
    const clickable = fieldCount > 0;
    return (
      <ToolCallCard
        icon={PenLine}
        label="Drafting content"
        detail={
          fieldCount > 0
            ? `${fieldCount} field${fieldCount > 1 ? "s" : ""} - click to view`
            : undefined
        }
        status={status}
        onClick={
          clickable
            ? () => setDraftingState({ draftedFields: fields })
            : undefined
        }
      />
    );
  },
});

/** UI for the set_field_content tool call. */
export const SetFieldContentToolUI = makeAssistantToolUI<
  Record<string, unknown>,
  unknown
>({
  toolName: "set_field_content",
  render: ({ args, status }) => {
    const fieldCount = args ? Object.keys(args).length : 0;
    return (
      <ToolCallCard
        icon={PenLine}
        label="Generating drafted content"
        detail={
          fieldCount > 0
            ? `${fieldCount} field${fieldCount > 1 ? "s" : ""}`
            : undefined
        }
        status={status}
      />
    );
  },
});

/** UI for the regenerate_fields tool call. */
export const RegenerateFieldsToolUI = makeAssistantToolUI<
  { fields: string[] },
  unknown
>({
  toolName: "regenerate_fields",
  render: ({ args, status }) => {
    const fields = args?.fields ?? [];
    return (
      <ToolCallCard
        icon={RefreshCw}
        label="Regenerating fields"
        detail={fields.length > 0 ? fields.join(", ") : undefined}
        status={status}
      />
    );
  },
});

/** UI for the save_draft_revision tool call. */
export const SaveDraftRevisionToolUI = makeAssistantToolUI<
  Record<string, unknown>,
  unknown
>({
  toolName: "save_draft_revision",
  render: ({ status }) => (
    <ToolCallCard
      icon={Save}
      label="Saving draft revision"
      detail="Creating an unpublished revision"
      status={status}
    />
  ),
});
