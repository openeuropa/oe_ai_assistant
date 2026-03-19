/**
 * Types for the Echo dev plugin.
 *
 * The echo plugin sends a message to a mock API and receives the
 * same message back word-by-word as an SSE stream, simulating
 * the AI streaming pattern used by the content drafting plugin.
 */

/** Payload sent to the echo API endpoint. */
export interface EchoRequest {
  message: string;
}

/** A single SSE event emitted by the echo stream. */
export interface EchoStreamEvent {
  /** The word being streamed back. */
  word: string;
  /** Zero-based index of this word in the original message. */
  index: number;
  /** True when this is the last event in the stream. */
  done: boolean;
}

/** Possible states of the echo stream. */
export type EchoStatus = "idle" | "streaming" | "done" | "error";
