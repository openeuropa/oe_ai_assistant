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
 * Extracts the existing messages from the current repository, appends the
 * new event message, and rebuilds the repository via
 * ExportedMessageRepository.fromArray so that assistant-ui normalizes every
 * entry. Hand-built entries bypass that normalization and cause a TypeError
 * during import (reading 'type' on undefined).
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
  // Extract plain message shapes from the current repository so they can be
  // passed as ThreadMessageLike values to fromArray.
  const existing = thread
    .export()
    .messages.map((entry) => entry.message as unknown as ThreadMessageLike);

  // Rebuild via fromArray: this lets assistant-ui normalize every entry,
  // including the new one, avoiding the crash that occurs when a partially
  // formed object is imported directly.
  thread.import(
    ExportedMessageRepository.fromArray([
      ...existing,
      buildEventThreadMessage(event),
    ]),
  );
}
