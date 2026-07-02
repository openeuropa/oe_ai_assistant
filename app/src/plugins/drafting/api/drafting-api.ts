/**
 * API helpers for the drafting plugin.
 *
 * Sends requests to our RPC-style wrapper endpoint which
 * delegates to the AG-UI controller internally. The response
 * is an SSE stream of AG-UI protocol events.
 */

import { getConfig } from "@/config";
import type {
  DraftingChatRequest,
  DraftingSetToneRequest,
  DraftingSetToneResponse,
} from "../types";

/**
 * Sends a chat message and returns the raw Response for SSE
 * consumption. The response body is a stream of AG-UI events.
 */
export async function postDraftingChat(
  request: DraftingChatRequest,
): Promise<Response> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/chat`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify(request),
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting API error: ${response.status}`);
  }
  return response;
}

/** Resets the conversation thread and returns a new thread ID. */
export async function resetDraftingThread(threadId: string): Promise<string> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/reset`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ threadId }),
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting reset error: ${response.status}`);
  }
  const data = (await response.json()) as { threadId: string };
  return data.threadId;
}

/** Sets the selected tone for drafting. */
export async function setDraftingTone(
  request: DraftingSetToneRequest,
): Promise<DraftingSetToneResponse> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/set-tone`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify(request),
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting set-tone error: ${response.status}`);
  }
  return (await response.json()) as DraftingSetToneResponse;
}
