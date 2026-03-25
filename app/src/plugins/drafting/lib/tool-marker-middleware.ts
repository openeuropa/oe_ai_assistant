/**
 * AG-UI middleware that detects [TOOL:name] markers in text content.
 *
 * The LLM outputs [TOOL:draft_content] at the START of its text
 * message, before the conversational text. Since it appears in the
 * first few streaming chunks (before any pause), the middleware
 * detects it quickly, strips it from the chat, and fires a callback
 * so the UI can show a loader immediately.
 *
 * The marker arrives split across multiple SSE chunks (e.g. "\n[",
 * "TOOL", ":draft_content", "]\n"). The middleware buffers text
 * until the marker is found or ruled out, then flushes normally.
 */

import type { Observable } from "rxjs";

/**
 * Matches [TOOL:name] in the accumulated buffer.
 *
 * Tolerates optional surrounding markdown formatting (e.g. ** or
 * `) that the LLM might add despite instructions not to. The
 * closing bracket is required since the marker appears at the
 * start of the message and completes before any long pause.
 */
const MARKER_RE = /\*{0,2}`?\[TOOL:(\w+)\]`?\*{0,2}/;

/**
 * Tests whether the buffer ends with a partial [TOOL:...] prefix
 * that might be completed by the next chunk. Also handles leading
 * markdown formatting (** or `) that the LLM might add.
 */
function isPartialMarker(buf: string): boolean {
  // Strip trailing markdown formatting before checking.
  const stripped = buf.replace(/[\s*`]+$/, "");

  const idx = stripped.lastIndexOf("[");
  if (idx === -1) {
    // No bracket yet, but might have leading formatting before it.
    // Check if the buffer ends with **, `, or * that could precede [.
    return /[*`]$/.test(buf);
  }
  const tail = stripped.slice(idx);

  // Fixed prefix before the tool name.
  if ("[TOOL:".startsWith(tail)) return true;

  // [TOOL: followed by word characters (name still growing).
  if (/^\[TOOL:\w+$/.test(tail)) return true;

  return false;
}

/**
 * Creates an AG-UI middleware that detects tool markers in text.
 *
 * Buffers TEXT_MESSAGE_CONTENT deltas until the marker is found
 * or ruled out. Non-text events pass through unchanged.
 *
 * @param onToolMarker - Called with the tool name when detected.
 */
export function createToolMarkerMiddleware(
  onToolMarker: (toolName: string) => void,
) {
  // biome-ignore lint/suspicious/noExplicitAny: AG-UI middleware types
  return (input: any, next: any): Observable<any> => {
    const source = next.run(input);

    const ObservableCtor = source.constructor as {
      // biome-ignore lint/suspicious/noExplicitAny: AG-UI event types
      new (subscribe: (observer: any) => () => void): Observable<any>;
    };

    // biome-ignore lint/suspicious/noExplicitAny: AG-UI event types
    return new ObservableCtor((observer: any) => {
      // Buffer for accumulating text across chunks.
      let buffer = "";
      // Once found (or ruled out), stop buffering.
      let resolved = false;
      // Template event for re-emitting buffered text.
      // biome-ignore lint/suspicious/noExplicitAny: AG-UI event type
      let lastTextEvent: any = null;

      /** Flush the buffer as a single text content event. */
      function flushBuffer() {
        if (buffer.length > 0 && lastTextEvent) {
          observer.next({ ...lastTextEvent, delta: buffer });
          buffer = "";
        }
      }

      const subscription = source.subscribe({
        // biome-ignore lint/suspicious/noExplicitAny: AG-UI event type
        next(event: any) {
          // Non-text events: flush buffer and pass through.
          if (event?.type !== "TEXT_MESSAGE_CONTENT") {
            if (!resolved) {
              // No marker found before a non-text event.
              // Flush whatever we have and stop looking.
              resolved = true;
              flushBuffer();
            }
            observer.next(event);
            return;
          }

          // Already resolved: pass text through directly.
          if (resolved) {
            observer.next(event);
            return;
          }

          const delta: string = event.delta ?? "";
          lastTextEvent = event;
          buffer += delta;

          // Check for complete marker in buffer.
          const match = buffer.match(MARKER_RE);
          if (match?.[1]) {
            onToolMarker(match[1]);
            resolved = true;

            // Strip the marker from the buffer. Flush any text
            // that appeared before or after it.
            const cleaned = buffer
              .replace(MARKER_RE, "")
              .trim();
            buffer = cleaned;
            flushBuffer();
            return;
          }

          // Still accumulating a partial marker -- hold the buffer.
          if (isPartialMarker(buffer)) {
            return;
          }

          // No marker possible from this buffer content.
          // Stop buffering and flush everything.
          resolved = true;
          flushBuffer();
        },
        error(err: unknown) {
          observer.error(err);
        },
        complete() {
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
