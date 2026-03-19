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

import { useEffect, useRef, useState } from "react";
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
import { setDraftingState } from "../store";

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
 * Delay in milliseconds between each character when animating
 * streamed text. Lower values feel faster but less readable;
 * higher values give a visible typewriter effect.
 */
const CHAR_DELAY_MS = 12;

/**
 * Renders streamed assistant text with a client-side typewriter
 * animation. Buffers incoming deltas and reveals them character
 * by character at a fixed cadence, producing smooth output
 * regardless of how the server delivers chunks.
 */
function SmoothAssistantText({
  text,
  status,
}: {
  text: string;
  status: { type: string };
}) {
  const [displayed, setDisplayed] = useState("");
  const targetRef = useRef(text);
  const idxRef = useRef(0);
  const rafRef = useRef<ReturnType<typeof requestAnimationFrame> | null>(null);
  const lastTimeRef = useRef(0);

  // Keep target text in sync with incoming deltas.
  targetRef.current = text;

  useEffect(() => {
    /** Animate one frame: append characters until the per-char budget
     *  for this frame is exhausted, then schedule the next frame. */
    const step = (now: number) => {
      if (!lastTimeRef.current) lastTimeRef.current = now;
      const elapsed = now - lastTimeRef.current;
      const chars = Math.max(1, Math.floor(elapsed / CHAR_DELAY_MS));

      if (idxRef.current < targetRef.current.length) {
        idxRef.current = Math.min(
          idxRef.current + chars,
          targetRef.current.length,
        );
        setDisplayed(targetRef.current.slice(0, idxRef.current));
        lastTimeRef.current = now;
        rafRef.current = requestAnimationFrame(step);
      } else {
        rafRef.current = null;
      }
    };

    // Start or continue animating when new text arrives.
    if (idxRef.current < targetRef.current.length && !rafRef.current) {
      lastTimeRef.current = 0;
      rafRef.current = requestAnimationFrame(step);
    }

    return () => {
      if (rafRef.current) {
        cancelAnimationFrame(rafRef.current);
        rafRef.current = null;
      }
    };
  }, [text]);

  // When the message is complete and animation has caught up,
  // ensure we show the final text.
  useEffect(() => {
    if (status.type !== "running" && displayed !== text) {
      idxRef.current = text.length;
      setDisplayed(text);
    }
  }, [status.type, text, displayed]);

  const isStreaming = status.type === "running";

  return (
    <div className="text-sm prose prose-sm max-w-none">
      <Streamdown isAnimating={isStreaming}>{displayed}</Streamdown>
    </div>
  );
}

/** Renders a single assistant message. */
function AssistantMessage() {
  return (
    <MessagePrimitive.Root className="mb-4 flex justify-start">
      <div className="max-w-[80%]">
        <div className="rounded-lg bg-gray-100 px-4 py-2 text-gray-900">
          <MessagePrimitive.Content
            components={{ Text: SmoothAssistantText }}
          />
        </div>
        <MessageError />
      </div>
    </MessagePrimitive.Root>
  );
}

/** Mark that the user has prompted when the form submits. */
function handleComposerSubmit() {
  setDraftingState({ hasPrompted: true });
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
