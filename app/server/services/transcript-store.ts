/**
 * In-memory transcript store for the drafting dev server.
 *
 * Mirrors the Drupal ai_conversation_message store for the parts the frontend
 * rehydrates: the user-visible turns and, for a draft, the draft_content tool
 * call carrying the drafted fields as its result. Keyed by session id; data is
 * lost on restart, which is fine for a dev server.
 */

/** A tool call stored on a transcript message (OpenAI render shape). */
export interface TranscriptToolCall {
  function: { name: string };
  /** Structured tool output (e.g. drafted field values). */
  result?: Record<string, unknown>;
}

/** A user-visible transcript entry, matching get_messages output. */
export interface TranscriptMessage {
  role: string;
  content: string;
  toolCalls?: TranscriptToolCall[];
}

export class TranscriptStore {
  private store = new Map<string, TranscriptMessage[]>();

  /** Returns the transcript for a session, oldest first. */
  load(sessionId: string): TranscriptMessage[] {
    return [...(this.store.get(sessionId) ?? [])];
  }

  /** Appends one message to a session's transcript. */
  append(sessionId: string, message: TranscriptMessage): void {
    const transcript = this.store.get(sessionId) ?? [];
    transcript.push(message);
    this.store.set(sessionId, transcript);
  }

  /** Clears a session's transcript. */
  delete(sessionId: string): void {
    this.store.delete(sessionId);
  }
}
