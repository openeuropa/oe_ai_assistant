/**
 * Thread event splice utilities.
 *
 * Builds synthetic assistant messages carrying editorial_event tool-call
 * parts and splices them into the live assistant-ui thread via the
 * export/import API. The shape mirrors what hydrate-transcript.ts produces
 * for server-persisted events so the chip renders through the existing
 * EditorialEventToolUI path without any additional wiring.
 */

import {
  ExportedMessageRepository,
  type ThreadMessageLike,
} from "@assistant-ui/react";

/** A locally generated editorial event to splice into the thread. */
export interface LocalThreadEvent {
  /** Machine-readable event type (e.g. "tone", "template", "error"). */
  eventType: string;
  /** Human-readable summary that appears inside the chip. */
  summary: string;
}

/**
 * Monotonically increasing counter for generating unique tool-call ids.
 *
 * Incrementing per call ensures two chips of the same type never collide
 * within a session, even if they occur in the same render cycle.
 */
let localEventCounter = 0;

/**
 * Builds the synthetic assistant message carrying the event chip part.
 *
 * The resulting message is structurally identical to what hydrate-transcript
 * produces for a server-persisted event, except the id uses a local counter
 * prefix and the args omit the "at" timestamp (it was not produced locally).
 */
export function buildEventThreadMessage(
  event: LocalThreadEvent,
): ThreadMessageLike {
  localEventCounter += 1;
  const toolCallId = `event-local-${localEventCounter}`;

  return {
    role: "assistant",
    content: [
      {
        type: "tool-call",
        toolCallId,
        toolName: "editorial_event",
        args: {
          eventType: event.eventType,
          summary: event.summary,
        },
        result: {},
      },
    ],
  } as unknown as ThreadMessageLike;
}

/**
 * Appends the event to the live thread without any network access.
 *
 * Only the new event message is normalized via
 * ExportedMessageRepository.fromArray; hand-built entries bypass that
 * normalization and cause a TypeError during import (reading 'type' on
 * undefined). The exported messages are already normalized ThreadMessages,
 * so they are re-imported untouched, preserving their parentId links and
 * any branch structure. The event is parented onto the exported head and
 * becomes the new head.
 *
 * The thread parameter uses structural typing so this function is
 * testable with a plain fake object without importing the full runtime.
 */
export function appendEventToThread(
  thread: {
    export(): ExportedMessageRepository;
    import(repo: ExportedMessageRepository): void;
  },
  event: LocalThreadEvent,
): void {
  const exported = thread.export();

  // Normalize the hand-built event message through fromArray so it carries
  // the id, status, and part shapes assistant-ui expects on import. The
  // guard only narrows the indexed access; one input yields one item.
  const [eventItem] = ExportedMessageRepository.fromArray([
    buildEventThreadMessage(event),
  ]).messages;
  if (eventItem === undefined) {
    return;
  }

  thread.import({
    headId: eventItem.message.id,
    messages: [
      ...exported.messages,
      { message: eventItem.message, parentId: exported.headId ?? null },
    ],
  });
}
