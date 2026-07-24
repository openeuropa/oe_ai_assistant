/**
 * API helpers for the drafting plugin.
 *
 * Sends requests to our RPC-style wrapper endpoint which
 * delegates to the AG-UI controller internally. The chat response
 * is an SSE stream of AG-UI protocol events; reset returns JSON.
 * Every request is scoped to the current editorial session, read
 * from the app config. The session transcript (get-messages) lives
 * in the shared `@/api/session-messages` module.
 */

import { getConfig } from "@/config";
import type {
  DraftingChatRequest,
  DraftingGenerationSettings,
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

/** Resets the conversation for the current session. */
export async function resetDrafting(): Promise<void> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/reset`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ sessionId: getConfig().sessionId }),
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting reset error: ${response.status}`);
  }
}

/**
 * Loads the tone currently saved for the session.
 *
 * TODO: Replace the hardcoded value with a real request once the
 * backend persists and exposes the selected tone (a get-tone action
 * or the tone injected into the initial plugin config). For now the
 * value is stubbed so the client rehydration path is complete end to
 * end and only the backend read remains.
 */
export async function fetchDraftingTone(): Promise<DraftingGenerationSettings> {
  return { toneId: "clear-professional" };
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
