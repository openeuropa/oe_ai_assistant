/**
 * In-memory conversation store for the dev server.
 *
 * Mirrors the Drupal ai_conversation_message store: a single store holds every
 * message keyed by session id, each with its Mistral `role` (user, assistant,
 * tool, system). The LLM loop reads back the full history for context, and
 * hydration (get-messages) filters the same store by role. Data is lost on
 * restart, which is fine for a dev server: reload hydration is exercised
 * against the real backend, not here.
 */

import type {
  AssistantMessage,
  SystemMessage,
  ToolMessage,
  UserMessage,
} from "@mistralai/mistralai/models/components";

/** Union of all Mistral message types stored in history. */
export type ChatMessage =
  | SystemMessage
  | UserMessage
  | AssistantMessage
  | ToolMessage;

/** A user-visible transcript entry returned by get-messages. */
export interface TranscriptMessage {
  role: string;
  content: string;
}

/** Default number of message pairs to keep. */
const DEFAULT_HISTORY_LENGTH = 10;

export class ConversationStore {
  private store = new Map<string, ChatMessage[]>();

  constructor(private readonly historyLength = DEFAULT_HISTORY_LENGTH) {}

  /**
   * Loads conversation history for a session.
   * Returns an empty array if no history exists.
   */
  load(sessionId: string): ChatMessage[] {
    return [...(this.store.get(sessionId) ?? [])];
  }

  /**
   * Saves conversation history, trimming to the configured
   * length. Trimming is boundary-safe: it never orphans a
   * tool result message from its preceding assistant message.
   */
  save(sessionId: string, messages: ChatMessage[]): void {
    const maxMessages = this.historyLength * 2;
    let trimmed = messages.slice(-maxMessages);

    // Drop any leading tool messages that would be orphaned
    // from their assistant message (which was trimmed off).
    while (trimmed.length > 0 && trimmed[0]!.role === "tool") {
      trimmed = trimmed.slice(1);
    }

    this.store.set(sessionId, trimmed);
  }

  /**
   * Returns the user-visible transcript for a session.
   *
   * Filters the stored history to the user and assistant text turns, the same
   * way the Drupal get-messages endpoint filters by role.
   */
  getTranscript(sessionId: string): TranscriptMessage[] {
    const transcript: TranscriptMessage[] = [];
    for (const m of this.load(sessionId)) {
      if (m.role !== "user" && m.role !== "assistant") {
        continue;
      }
      const content = typeof m.content === "string" ? m.content : "";
      if (content !== "") {
        transcript.push({ role: m.role, content });
      }
    }
    return transcript;
  }

  /** Deletes conversation history for a session. */
  delete(sessionId: string): void {
    this.store.delete(sessionId);
  }
}
