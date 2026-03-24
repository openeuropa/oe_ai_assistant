/**
 * AG-UI middleware that detects [TOOL:name] markers in text content.
 *
 * The LLM outputs a [TOOL:draft_content] marker in its text stream
 * right before calling the tool. This marker arrives as a regular
 * text token while Mistral is still generating the tool call
 * arguments server-side. The middleware:
 *
 * 1. Intercepts TEXT_MESSAGE_CONTENT events.
 * 2. Detects the [TOOL:draft_content] marker pattern.
 * 3. Strips the marker from the text so it does not appear in chat.
 * 4. Calls the onToolMarker callback so the UI can show a loader
 *    immediately, before TOOL_CALL_START arrives.
 *
 * This solves a Mistral-specific limitation: Mistral does not stream
 * tool call arguments incrementally. The entire tool call (name,
 * arguments, finish_reason) arrives in a single SSE frame. Without
 * this marker, TOOL_CALL_START and STATE_SNAPSHOT arrive at the
 * same moment, giving the browser no time to render a loader.
 */

import type { Observable } from "rxjs";

/** Regex matching [TOOL:tool_name] markers in text content. */
const TOOL_MARKER_RE = /\[TOOL:(\w+)\]/g;

/**
 * Creates an AG-UI middleware that detects tool markers in text.
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
      const subscription = source.subscribe({
        // biome-ignore lint/suspicious/noExplicitAny: AG-UI event type
        next(event: any) {
          // Only inspect TEXT_MESSAGE_CONTENT events.
          if (event?.type !== "TEXT_MESSAGE_CONTENT") {
            observer.next(event);
            return;
          }

          const delta: string = event.delta ?? "";

          // Check for [TOOL:name] marker in the text delta.
          // Reset lastIndex since the regex has the global flag.
          TOOL_MARKER_RE.lastIndex = 0;
          if (TOOL_MARKER_RE.test(delta)) {
            // Extract tool name and fire the callback.
            const match = delta.match(/\[TOOL:(\w+)\]/);
            if (match?.[1]) {
              onToolMarker(match[1]);
            }

            // Strip the marker from the text. If the delta was
            // only the marker (plus whitespace), skip the event
            // entirely so no empty text appears in chat.
            const cleaned = delta.replace(TOOL_MARKER_RE, "").trim();
            if (cleaned.length === 0) {
              return;
            }

            // Re-emit with cleaned text.
            observer.next({ ...event, delta: cleaned });
            return;
          }

          // No marker found -- pass through unchanged.
          observer.next(event);
        },
        error(err: unknown) {
          observer.error(err);
        },
        complete() {
          observer.complete();
        },
      });

      return () => {
        subscription.unsubscribe();
      };
    });
  };
}
