/**
 * Chat thread component for the drafting plugin.
 *
 * Composes assistant-ui primitives into a chat interface with
 * a welcome message, message list, file attachments, and a
 * composer input. Powered by the AG-UI runtime for streaming
 * responses and tool call visualization.
 */

import {
  ActionBarPrimitive,
  AttachmentPrimitive,
  ComposerPrimitive,
  MessagePrimitive,
  ThreadPrimitive,
  useAuiState,
} from "@assistant-ui/react";

import { Streamdown } from "streamdown";
import "streamdown/styles.css";
import {
  AlertCircle,
  Check,
  Copy,
  FileText,
  PenLine,
  SendHorizontal,
  X,
} from "lucide-react";
import type { PaneTabItem } from "@/components/ui/pane-tabs";
import { avatarColorClass, UserAvatar } from "@/components/ui/user-avatar";
import { getConfig } from "@/config";
import { useAppStore } from "@/store";
import { ContextButtons } from "./context-buttons";
import { ToolFallbackCard } from "./tool-uis";

/** Welcome message shown when the chat is empty. */
function WelcomeMessage() {
  return (
    <ThreadPrimitive.Empty>
      <div className="flex flex-col items-center gap-4 px-6 py-12 text-center">
        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-600">
          <PenLine size={24} />
        </div>
        <div className="space-y-2">
          <h2 className="text-base font-semibold text-gray-900">
            Content Drafting Assistant
          </h2>
          <p className="max-w-sm text-sm text-gray-500">
            Describe the content you want to create and I will generate a
            structured draft based on the content type fields. You can then
            review, reject, and regenerate individual fields.
          </p>
        </div>
        <div className="flex flex-wrap justify-center gap-2 pt-2">
          <SuggestionChip text="Draft a news article about the EU AI Act" />
          <SuggestionChip text="Write a press release about a new policy" />
        </div>
      </div>
    </ThreadPrimitive.Empty>
  );
}

/** Clickable suggestion chip shown in the welcome screen. */
function SuggestionChip({ text }: { text: string }) {
  return (
    <ThreadPrimitive.Suggestion prompt={text} method="replace" autoSend>
      <span className="cursor-pointer rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-600 transition-colors hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
        {text}
      </span>
    </ThreadPrimitive.Suggestion>
  );
}

/** Attachment badge for a sent user message. */
function UserMessageAttachment() {
  return (
    <div className="flex items-center gap-1.5 rounded-md border border-blue-400 bg-blue-500 px-2 py-2">
      <FileText size={14} className="shrink-0 text-blue-200" />
      <span className="text-xs text-white">
        <AttachmentPrimitive.Name />
      </span>
    </div>
  );
}

/** Renders a single user message bubble with attachments and avatar. */
function UserMessage() {
  // The author's name travels in the message metadata for shared
  // sessions; the current user's own turns fall back to the config.
  const authorName = useAuiState(
    (s) =>
      (s.message.metadata?.custom as { userName?: string } | undefined)
        ?.userName,
  );
  const name = authorName ?? getConfig().userName;
  // The author's color follows their position in the participants list.
  const participants = useAppStore((s) => s.sessionParticipants);
  const colorClass = avatarColorClass(participants.indexOf(name));

  return (
    <MessagePrimitive.Root className="mb-4 flex flex-col items-end gap-1">
      {/* Attachments shown above the message text */}
      <MessagePrimitive.Attachments
        components={{ Attachment: UserMessageAttachment }}
      />
      <div className="flex max-w-[80%] items-start justify-end gap-2">
        {/* The sharp top-right corner points at the avatar like a comic
            speech bubble tail; the bubble shares the author's color. */}
        <div
          className={`min-w-0 rounded-lg rounded-tr-none px-4 py-2 text-white ${colorClass}`}
        >
          <MessagePrimitive.Content
            components={{
              Text: ({ text }) => <p className="text-base">{text}</p>,
            }}
          />
        </div>
        <UserAvatar name={name} colorClass={colorClass} />
      </div>
    </MessagePrimitive.Root>
  );
}

/** Shows an error message when the assistant run fails. */
function MessageError() {
  const status = useAuiState((s) => s.message?.status);
  if (!status || status.type !== "incomplete" || status.reason !== "error") {
    return null;
  }
  const errorText = (status as Record<string, unknown>).error as
    | string
    | undefined;
  return (
    <div className="mt-2 flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2">
      <AlertCircle size={16} className="mt-0.5 shrink-0 text-red-500" />
      <p className="text-sm text-red-700">
        {errorText || "An error occurred while processing your request."}
      </p>
    </div>
  );
}

/**
 * Renders streamed assistant text with Streamdown for incremental
 * markdown rendering. Text deltas arrive from the LLM stream at
 * the provider's natural pace.
 */
function AssistantText({
  text,
  status,
}: {
  text: string;
  status: { type: string };
}) {
  return (
    <div className="chat-markdown text-base prose max-w-none">
      <Streamdown isAnimating={status.type === "running"}>{text}</Streamdown>
    </div>
  );
}

/**
 * Typing indicator with three bouncing dots, shown in place of the
 * assistant message while the assistant is processing but has not
 * yet started streaming content. Once the first content part
 * arrives, the indicator is replaced by the message text. The wave
 * motion comes from the typing-dot class in index.css, which staggers
 * each dot by its position. Exported for Storybook.
 */
export function TypingIndicator() {
  return (
    <div className="flex items-center gap-1.5 py-2">
      {[0, 1, 2].map((i) => (
        <span
          key={i}
          className="typing-dot inline-block h-2 w-2 rounded-full bg-gray-400"
        />
      ))}
    </div>
  );
}

/**
 * Formats a message timestamp as a compact local date with 24h time.
 */
function formatMessageTime(createdAt: Date): string {
  return createdAt.toLocaleString(undefined, {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

/**
 * Action row under an assistant message: the copy-to-clipboard control
 * (provided by assistant-ui's action bar) and the message timestamp.
 * Hidden while the message is still streaming.
 */
function AssistantMessageFooter() {
  const isCopied = useAuiState((s) => s.message.isCopied);
  const createdAt = useAuiState((s) => s.message.createdAt);

  return (
    <ActionBarPrimitive.Root
      hideWhenRunning
      className="mt-1 flex items-center gap-2 text-xs text-gray-400"
    >
      <ActionBarPrimitive.Copy
        aria-label="Copy message"
        className="flex cursor-pointer items-center rounded-md p-1 hover:bg-gray-200 hover:text-gray-600"
      >
        {isCopied ? <Check size={14} /> : <Copy size={14} />}
      </ActionBarPrimitive.Copy>
      <span>{formatMessageTime(createdAt)}</span>
    </ActionBarPrimitive.Root>
  );
}

/**
 * Renders a single assistant message, or the typing indicator
 * when the message has no content yet (waiting for the LLM to
 * start streaming), so the user sees pulsating dots until real
 * content arrives.
 *
 * Assistant responses sit directly on the chat surface with no
 * bubble; only user messages keep one. Event-only messages (all
 * parts are editorial_event tool-calls) render with a tighter
 * bottom margin so consecutive chips stay grouped.
 */
function AssistantMessage() {
  const content = useAuiState((s) => s.message?.content ?? []);
  const hasContent = content.length > 0;

  // Detect event-only messages: all parts are editorial_event tool-calls.
  // These are injected by the history adapter and carry no text parts.
  const isEventOnly =
    hasContent &&
    content.every(
      (part) =>
        part.type === "tool-call" &&
        (part as { toolName?: string }).toolName === "editorial_event",
    );

  // The copy/timestamp footer only makes sense under textual replies;
  // tool-call-only messages (draft cards, saves) render without it.
  const hasText = content.some(
    (part) =>
      part.type === "text" &&
      ((part as { text?: string }).text ?? "").trim().length > 0,
  );

  // Show pulsating dots while waiting for the first content
  // part (text or tool call) to arrive from the backend.
  if (!hasContent) {
    return (
      <MessagePrimitive.Root className="mb-4">
        <TypingIndicator />
      </MessagePrimitive.Root>
    );
  }

  // Event-only messages render chips without the bubble wrapper so they
  // stay centered and visually distinct from conversational turns.
  if (isEventOnly) {
    return (
      <MessagePrimitive.Root className="mb-2">
        <MessagePrimitive.Content
          components={{
            Text: AssistantText,
            tools: { Fallback: ToolFallbackCard },
          }}
        />
      </MessagePrimitive.Root>
    );
  }

  return (
    <MessagePrimitive.Root className="mb-4" data-testid="assistant-message">
      {/* Assistant responses render directly on the chat surface with no
          bubble; only user messages keep one. */}
      <div className="text-gray-900">
        <MessagePrimitive.Content
          components={{
            Text: AssistantText,
            tools: { Fallback: ToolFallbackCard },
          }}
        />
      </div>
      <MessageError />
      {hasText && <AssistantMessageFooter />}
    </MessagePrimitive.Root>
  );
}

/** A pending attachment in the composer, with a remove button. */
function ComposerAttachment() {
  return (
    <div className="flex items-center gap-1.5 rounded-md border border-gray-200 bg-gray-50 px-2 py-2">
      <FileText size={14} className="shrink-0 text-gray-400" />
      <span className="text-xs text-gray-600">
        <AttachmentPrimitive.Name />
      </span>
      <AttachmentPrimitive.Remove>
        <span className="flex cursor-pointer items-center text-gray-400 hover:text-gray-600">
          <X size={12} />
        </span>
      </AttachmentPrimitive.Remove>
    </div>
  );
}

/**
 * Chat composer arranged like the Claude web interface: a rounded box
 * with the text input on top and a bottom row holding the editorial
 * context pill buttons on the left and the send button on the right.
 */
function Composer({
  tabs,
  defaultActiveTabId,
}: {
  tabs: PaneTabItem[];
  defaultActiveTabId?: string;
}) {
  return (
    <ComposerPrimitive.Root className="p-4 pt-2">
      <div className="rounded-xl border border-gray-300 bg-white shadow-sm focus-within:border-blue-500">
        {/* Pending attachments row (populated by the documents panel). */}
        <div className="flex flex-wrap gap-1 empty:hidden [&:not(:empty)]:px-3 [&:not(:empty)]:pt-3">
          <ComposerPrimitive.Attachments
            components={{ Attachment: ComposerAttachment }}
          />
        </div>

        {/* Text input on top, sized like the conversation messages. */}
        <ComposerPrimitive.Input
          placeholder="Describe the content you want to draft..."
          className="w-full resize-none border-0 bg-transparent px-3 pt-3 text-base focus:outline-none"
          autoFocus
        />

        {/* Bottom row: context pills left, send right. */}
        <div className="flex items-end justify-between gap-2 p-2 pl-3">
          <ContextButtons tabs={tabs} defaultActiveTabId={defaultActiveTabId} />
          <ComposerPrimitive.Send className="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
            <SendHorizontal size={16} />
          </ComposerPrimitive.Send>
        </div>
      </div>
    </ComposerPrimitive.Root>
  );
}

interface DraftingThreadProps {
  /** Context panels shown as pill buttons inside the composer. */
  tabs?: PaneTabItem[];
  /** Opens a panel modal by id on mount (Storybook/previews). */
  defaultActiveTabId?: string;
}

/**
 * Full chat thread with welcome, messages, and composer. The scroll
 * container spans the whole chat area so the scrollbar sits at its
 * outer edge, while the messages and the composer are centered with a
 * comfortable reading width.
 */
export function DraftingThread({
  tabs = [],
  defaultActiveTabId,
}: DraftingThreadProps) {
  return (
    <ThreadPrimitive.Root className="flex min-h-0 flex-1 flex-col">
      <ThreadPrimitive.Viewport className="flex-1 overflow-y-auto">
        <div className="mx-auto w-full max-w-3xl p-4">
          <WelcomeMessage />
          <ThreadPrimitive.Messages
            components={{
              UserMessage,
              AssistantMessage,
            }}
          />
        </div>
      </ThreadPrimitive.Viewport>
      <div className="mx-auto w-full max-w-3xl">
        <Composer tabs={tabs} defaultActiveTabId={defaultActiveTabId} />
      </div>
    </ThreadPrimitive.Root>
  );
}
