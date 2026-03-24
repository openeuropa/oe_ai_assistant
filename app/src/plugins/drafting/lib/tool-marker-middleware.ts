/**
 * AG-UI middleware that detects [TOOL:name] markers in text content.
 *
 * The LLM outputs a [TOOL:draft_content] marker in its text stream
 * right before calling the tool. Mistral splits this across multiple
 * SSE chunks (e.g. "[", "TOOL", ":draft_content", "]"), so the
 * middleware buffers text deltas and scans the accumulated buffer
 * for the complete marker pattern.
 *
 * When the marker is detected:
 * 1. The callback fires so the UI can show a loader immediately.
 * 2. The marker text is stripped from the buffer.
 * 3. Any non-marker text before the marker is flushed to chat.
 * 4. Chunks after the marker pass through normally.
 *
 * This solves a Mistral-specific limitation: Mistral does not stream
 * tool call arguments incrementally. The entire tool call arrives in
 * a single SSE frame. Without this marker, TOOL_CALL_START and
 * STATE_SNAPSHOT arrive at the same moment, giving the browser no
 * time to render a loader.
 */

import type { Observable } from "rxjs";

/** Full marker pattern to detect in the accumulated text buffer. */
const MARKER_RE = /\[TOOL:(\w+)\]/;

/**
 * Partial prefix patterns that indicate a marker MAY be forming.
 * If the buffer ends with any of these, we hold it instead of
 * flushing, because the next chunk might complete the marker.
 *
 * Ordered longest-first so we match the most specific prefix.
 */
const PARTIAL_PREFIXES = [
  "[TOOL:draft_content",
  "[TOOL:draft_conten",
  "[TOOL:draft_conte",
  "[TOOL:draft_cont",
  "[TOOL:draft_con",
  "[TOOL:draft_co",
  "[TOOL:draft_c",
  "[TOOL:draft_",
  "[TOOL:draft",
  "[TOOL:draf",
  "[TOOL:dra",
  "[TOOL:dr",
  "[TOOL:d",
  "[TOOL:",
  "[TOOL",
  "[TOO",
  "[TO",
  "[T",
  "[",
];

/**
 * Creates an AG-UI middleware that detects tool markers in text.
 *
 * Buffers TEXT_MESSAGE_CONTENT deltas and scans the accumulated
 * text for the [TOOL:name] pattern. Non-text events pass through
 * unchanged. When a TEXT_MESSAGE_END arrives, any remaining buffer
 * is flushed.
 *
 * @param onToolMarker - Called with the tool name when a marker is
 *   detected. Use this to set isDrafting=true in the store.
 */
export function createToolMarkerMiddleware(
  onToolMarker: (toolName: string) => void,
) {
  // biome-ignore lint/suspicious/noExplicitAny: AG-UI middleware types use any
  return (input: any, next: any): Observable<any> => {
    const source = next.run(input);

    // Use the source's Observable constructor to avoid rxjs
    // version conflicts (same pattern as event-smoothing.ts).
    const ObservableCtor = source.constructor as {
      // biome-ignore lint/suspicious/noExplicitAny: AG-UI event types
      new (subscribe: (observer: any) => () => void): Observable<any>;
    };

    // biome-ignore lint/suspicious/noExplicitAny: AG-UI event types
    return new ObservableCtor((observer: any) => {
      // Accumulated text buffer for cross-chunk marker detection.
      let buffer = "";
      // Whether the marker has been found (stop buffering after).
      let markerFound = false;
      // The last TEXT_MESSAGE_CONTENT event template (for re-emit).
      // biome-ignore lint/suspicious/noExplicitAny: AG-UI event type
      let lastTextEvent: any = null;

      /**
       * Flushes accumulated buffer text as a TEXT_MESSAGE_CONTENT
       * event. Uses the last seen text event as a template so the
       * messageId and other fields are correct.
       */
      function flushBuffer() {
        if (buffer.length > 0 && lastTextEvent) {
          observer.next({ ...lastTextEvent, delta: buffer });
          buffer = "";
        }
      }

      const subscription = source.subscribe({
        // biome-ignore lint/suspicious/noExplicitAny: AG-UI event type
        next(event: any) {
          // Non-text events: flush any buffered text first, then
          // pass the event through. This ensures text appears in
          // the correct order relative to other events.
          if (event?.type !== "TEXT_MESSAGE_CONTENT") {
            if (!markerFound) {
              flushBuffer();
            }
            observer.next(event);
            return;
          }

          // After the marker was found, pass text through directly.
          if (markerFound) {
            observer.next(event);
            return;
          }

          const delta: string = event.delta ?? "";
          lastTextEvent = event;
          buffer += delta;

          // Check if the buffer contains a complete marker.
          const match = buffer.match(MARKER_RE);
          if (match?.[1]) {
            // Fire the callback with the tool name.
            onToolMarker(match[1]);
            markerFound = true;

            // Strip the marker and everything after it from the
            // buffer. Flush any text before the marker.
            const markerStart = buffer.indexOf(match[0]);
            const beforeMarker = buffer.slice(0, markerStart).trimEnd();
            buffer = beforeMarker;
            flushBuffer();
            return;
          }

          // Check if the buffer ends with a partial marker prefix.
          // If so, hold the buffer -- the next chunk may complete it.
          for (const prefix of PARTIAL_PREFIXES) {
            if (buffer.endsWith(prefix)) {
              // Still accumulating -- don't flush yet.
              return;
            }
          }

          // No marker and no partial prefix: flush the buffer.
          // Keep only the last character in case it starts a new
          // potential marker (e.g. "[").
          const safeEnd = buffer.length - 1;
          if (safeEnd > 0) {
            observer.next({
              ...lastTextEvent,
              delta: buffer.slice(0, safeEnd),
            });
            buffer = buffer.slice(safeEnd);
          }
        },
        error(err: unknown) {
          observer.error(err);
        },
        complete() {
          // Flush any remaining buffered text on completion.
          flushBuffer();
          observer.complete();
        },
      });

      return () => {
        subscription.unsubscribe();
      };
    });
  };
}
