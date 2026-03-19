/**
 * Echo response display.
 *
 * Renders the streamed words as they arrive, joined by spaces.
 * Shows a blinking cursor while streaming is in progress and
 * a status badge indicating the current state.
 */

import type { EchoStatus } from "../types";

interface EchoResponseProps {
  /** Accumulated words from the stream. */
  words: string[];
  /** Current stream status. */
  status: EchoStatus;
  /** Error message if the stream failed. */
  error: string | null;
  /** Called when the user wants to clear and start over. */
  onReset: () => void;
}

/** Maps status to a colored badge for visual feedback. */
const statusStyles: Record<EchoStatus, string> = {
  idle: "bg-gray-100 text-gray-600",
  streaming: "bg-blue-100 text-blue-700",
  done: "bg-green-100 text-green-700",
  error: "bg-red-100 text-red-700",
};

export function EchoResponse({
  words,
  status,
  error,
  onReset,
}: EchoResponseProps) {
  return (
    <div className="space-y-3">
      {/* Status bar with badge and reset button */}
      <div className="flex items-center justify-between">
        <span
          className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusStyles[status]}`}
        >
          {status}
        </span>
        {status !== "idle" && (
          <button
            type="button"
            onClick={onReset}
            className="text-xs text-gray-500 hover:text-gray-700"
          >
            Reset
          </button>
        )}
      </div>

      {/* Streamed text output */}
      {words.length > 0 && (
        <div className="rounded-md border border-gray-200 bg-gray-50 p-4 text-sm">
          {words.join(" ")}
          {/* Blinking cursor while streaming */}
          {status === "streaming" && (
            <span className="ml-0.5 inline-block animate-pulse">|</span>
          )}
        </div>
      )}

      {/* Error message */}
      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  );
}
