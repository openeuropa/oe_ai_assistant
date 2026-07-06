/**
 * Session conversation transcript API.
 *
 * `get-messages` is a generic action provided by the base plugin: it returns
 * the persisted transcript for an editorial session, independent of any
 * specific plugin. It is dispatched through the drafting plugin, which hosts
 * the conversation UI, but the data and naming are session-scoped.
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
  content: string;
  toolCalls?: SessionToolCall[];
}

/** Loads the persisted transcript for the current session. */
export async function getSessionMessages(): Promise<SessionMessage[]> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/get-messages`,
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
