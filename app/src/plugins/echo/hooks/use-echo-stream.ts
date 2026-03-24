/**
 * Hook for consuming the echo SSE stream.
 *
 * Manages the full lifecycle: sending a message to the API,
 * reading the streamed response word-by-word, accumulating words
 * into the plugin's store slice, and tracking status
 * (idle -> streaming -> done / error).
 *
 * State is stored in the global Zustand store under pluginStates["echo"],
 * making it accessible to other plugins and the shell. The SSE reader
 * ref is kept locally since it is a non-serializable side-effect handle.
 */

import { useCallback, useRef } from "react";
import { postEcho } from "../api/echo-api";
import { getEchoState, setEchoState, useEchoSlice } from "../store";
import type { EchoStatus, EchoStreamEvent } from "../types";

interface UseEchoStreamReturn {
  /** Accumulated words from the current stream. */
  words: string[];
  /** Current status of the stream. */
  status: EchoStatus;
  /** Error message if the stream failed. */
  error: string | null;
  /** Send a message and start streaming the echo response. */
  send: (message: string) => void;
  /** Reset the stream state back to idle. */
  reset: () => void;
}

export function useEchoStream(): UseEchoStreamReturn {
  // Read state from the plugin's store slice.
  const { words, status, error } = useEchoSlice();

  // Track the current reader so we can cancel on reset.
  const readerRef = useRef<ReadableStreamDefaultReader<Uint8Array> | null>(
    null,
  );

  const send = useCallback((message: string) => {
    setEchoState({ words: [], error: null, status: "streaming" });

    // Async IIFE to send the request and read the SSE stream.
    (async () => {
      try {
        // POST to the real echo API endpoint.
        const response = await postEcho({ message });
        const reader = response.body!.getReader();
        readerRef.current = reader;

        const decoder = new TextDecoder();
        let buffer = "";

        // Read the stream chunk by chunk and parse SSE frames.
        while (true) {
          const { done, value } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });

          // SSE frames are separated by double newlines.
          const frames = buffer.split("\n\n");
          // Keep the last (possibly incomplete) chunk in the buffer.
          buffer = frames.pop()!;

          for (const frame of frames) {
            const dataLine = frame
              .split("\n")
              .find((l) => l.startsWith("data: "));
            if (dataLine) {
              const parsed = JSON.parse(dataLine.slice(6));
              // The Drupal backend wraps payloads in AG-UI CUSTOM
              // events ({ type, name, value }), while the mock dev
              // server sends raw payloads. Handle both formats.
              const event = (parsed.value ?? parsed) as EchoStreamEvent;
              // Read current words from the store and append.
              const current = getEchoState();
              setEchoState({ words: [...current.words, event.word] });
            }
          }
        }
        setEchoState({ status: "done" });
      } catch (err) {
        setEchoState({
          error: err instanceof Error ? err.message : "Stream failed",
          status: "error",
        });
      }
    })();
  }, []);

  const reset = useCallback(() => {
    // Cancel any in-progress stream.
    readerRef.current?.cancel();
    readerRef.current = null;
    setEchoState({ words: [], status: "idle", error: null });
  }, []);

  return { words, status, error, send, reset };
}
