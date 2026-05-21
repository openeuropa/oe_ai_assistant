/**
 * API client for the agent-test backend plugin.
 */
import { getConfig } from "@/config";

/** Sends a chat message and returns the raw SSE Response. */
export async function postChat(message: string): Promise<Response> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/agent_test/chat`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ message }),
    },
  );
  if (!response.ok) {
    throw new Error(`Agent test API error: ${response.status}`);
  }
  return response;
}

/** Resets the conversation history. */
export async function postReset(): Promise<void> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/agent_test/reset`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({}),
    },
  );
  if (!response.ok) {
    throw new Error(`Agent test reset error: ${response.status}`);
  }
}
