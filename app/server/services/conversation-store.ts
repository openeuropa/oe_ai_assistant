/**
 * In-memory conversation history store.
 *
 * Mirrors Drupal's PrivateTempStore for the drafting plugin.
 * Each conversation is keyed by thread ID and stores an array
 * of Mistral SDK message objects. History is trimmed to a
 * configurable length on save.
 *
 * Data is lost on server restart, which is acceptable for a
 * dev/reference server.
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

/** Default number of message pairs to keep. */
const DEFAULT_HISTORY_LENGTH = 10;

export class ConversationStore {
  private store = new Map<string, ChatMessage[]>();

  constructor(private readonly historyLength = DEFAULT_HISTORY_LENGTH) {}

  /**
   * Loads conversation history for a thread.
   * Returns an empty array if no history exists.
   */
  load(threadId: string): ChatMessage[] {
    return [...(this.store.get(threadId) ?? [])];
  }

  /**
   * Saves conversation history, trimming to the configured
   * length. Trimming is boundary-safe: it never orphans a
   * tool result message from its preceding assistant message.
   */
  save(threadId: string, messages: ChatMessage[]): void {
    const maxMessages = this.historyLength * 2;
    let trimmed = messages.slice(-maxMessages);

    // Drop any leading tool messages that would be orphaned
    // from their assistant message (which was trimmed off).
    while (trimmed.length > 0 && trimmed[0]!.role === "tool") {
      trimmed = trimmed.slice(1);
    }

    this.store.set(threadId, trimmed);
  }

  /** Deletes conversation history for a thread. */
  delete(threadId: string): void {
    this.store.delete(threadId);
  }
}
