/**
 * Session conversation transcript API.
 *
 * `get-messages` is a generic action provided by the base plugin: it returns
 * the persisted transcript for an editorial session, independent of any
 * specific plugin. It is dispatched through a plugin id (the caller passes the
 * plugin that hosts the conversation), but the data and naming are
 * session-scoped.
 */

import { getConfig } from "@/config";

/** A tool call stored on a transcript message (OpenAI render shape). */
export interface SessionToolCall {
  id?: string;
  type?: string;
  function?: { name?: string; arguments?: string };
  /** Structured tool output (e.g. drafted field values). */
  result?: Record<string, unknown>;
}

/** A single user-visible transcript entry from get-messages. */
export interface SessionMessage {
  role: string;
  /** Message text; present on user and assistant items only. */
  content?: string;
  /** Display name of the author (user items in shared sessions). */
  userName?: string;
  toolCalls?: SessionToolCall[];
  /** Event type, e.g. "session_start", "tone", "template" (event items). */
  type?: string;
  /** Human-readable event summary (event items). */
  summary?: string;
  /** RFC 3339 timestamp of the event (event items). */
  at?: string;
}

/**
 * Loads the persisted transcript for the current session.
 *
 * @param pluginId The plugin the request is dispatched through (get-messages
 *   is available on any plugin; the transcript is scoped to the session).
 */
export async function getSessionMessages(
  pluginId: string,
): Promise<SessionMessage[]> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/${pluginId}/get-messages`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ sessionId: getConfig().sessionId }),
    },
  );
  if (!response.ok) {
    throw new Error(`get-messages error: ${response.status}`);
  }
  const data = (await response.json()) as { messages: SessionMessage[] };
  return data.messages;
}
