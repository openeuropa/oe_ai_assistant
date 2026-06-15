/**
 * Chat thread component for the drafting plugin.
 *
 * Composes assistant-ui primitives into a chat interface with
 * a welcome message, message list, file attachments, and a
 * composer input. Powered by the AG-UI runtime for streaming
 * responses and tool call visualization.
 */

import {
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
  FileText,
  Paperclip,
  PenLine,
  SendHorizontal,
  X,
} from "lucide-react";

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

/** Renders a single user message bubble with attachments. */
function UserMessage() {
  return (
    <MessagePrimitive.Root className="mb-4 flex flex-col items-end gap-1">
      {/* Attachments shown above the message text */}
      <MessagePrimitive.Attachments
        components={{ Attachment: UserMessageAttachment }}
      />
      <div className="max-w-[80%] rounded-lg bg-blue-600 px-4 py-2 text-white">
        <MessagePrimitive.Content
          components={{
            Text: ({ text }) => <p className="text-sm">{text}</p>,
          }}
        />
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
    <div className="text-sm prose prose-sm max-w-none">
      <Streamdown isAnimating={status.type === "running"}>{text}</Streamdown>
    </div>
  );
}

/**
 * Typing indicator with three pulsating dots, shown in place of
 * the assistant message bubble while the assistant is processing
 * but has not yet started streaming content. Once the first
 * content part arrives, the indicator is replaced by the normal
 * message bubble.
 *
 * Uses the animate-typing-pulse Tailwind utility registered
 * via @theme in index.css.
 */
function TypingIndicator() {
  return (
    <div className="flex justify-start">
      <div className="rounded-lg bg-gray-100 px-4 py-3">
        <div className="flex items-center gap-1.5">
          {[0, 1, 2].map((i) => (
            <span
              key={i}
              className="inline-block h-2 w-2 animate-typing-pulse rounded-full bg-gray-400"
              style={{ animationDelay: `${i * 0.2}s` }}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

/**
 * Renders a single assistant message, or the typing indicator
 * when the message has no content yet (waiting for the LLM to
 * start streaming). This ensures the empty chat bubble is never
 * visible -- the user sees pulsating dots until real content
 * arrives.
 */
function AssistantMessage() {
  const hasContent = useAuiState((s) => (s.message?.content?.length ?? 0) > 0);

  // Show pulsating dots while waiting for the first content
  // part (text or tool call) to arrive from the backend.
  if (!hasContent) {
    return (
      <MessagePrimitive.Root className="mb-4">
        <TypingIndicator />
      </MessagePrimitive.Root>
    );
  }

  return (
    <MessagePrimitive.Root
      className="mb-4 flex justify-start"
      data-testid="assistant-message"
    >
      <div className="max-w-[80%]">
        <div className="rounded-lg bg-gray-100 px-4 py-2 text-gray-900">
          <MessagePrimitive.Content components={{ Text: AssistantText }} />
        </div>
        <MessageError />
      </div>
    </MessagePrimitive.Root>
  );
}

/** Called when the composer form submits. */
function handleComposerSubmit() {
  // No-op: the runtime handles message submission.
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

/** Chat composer with text input, attachment button, and send. */
function Composer() {
  return (
    <ComposerPrimitive.Root
      className="border-t border-gray-200 p-4"
      onSubmit={handleComposerSubmit}
    >
      {/* Pending attachments row */}
      <div className="mb-1.5 flex flex-wrap gap-1">
        <ComposerPrimitive.Attachments
          components={{ Attachment: ComposerAttachment }}
        />
      </div>

      {/* Input row */}
      <div className="flex items-end gap-2">
        <ComposerPrimitive.AddAttachment>
          <span className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600">
            <Paperclip size={16} />
          </span>
        </ComposerPrimitive.AddAttachment>
        <ComposerPrimitive.Input
          placeholder="Describe the content you want to draft..."
          className="flex-1 resize-none rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
          autoFocus
        />
        <ComposerPrimitive.Send className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
          <SendHorizontal size={16} />
        </ComposerPrimitive.Send>
      </div>
    </ComposerPrimitive.Root>
  );
}

/** Full chat thread with welcome, messages, and composer. */
export function DraftingThread() {
  return (
    <ThreadPrimitive.Root className="flex min-h-0 flex-1 flex-col">
      <ThreadPrimitive.Viewport className="flex-1 overflow-y-auto p-4">
        <WelcomeMessage />
        <ThreadPrimitive.Messages
          components={{
            UserMessage,
            AssistantMessage,
          }}
        />
      </ThreadPrimitive.Viewport>
      <Composer />
    </ThreadPrimitive.Root>
  );
}
