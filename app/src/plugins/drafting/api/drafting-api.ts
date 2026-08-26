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
  DraftingSaveRequest,
  DraftingSaveResponse,
  DraftingSetTemplateRequest,
  DraftingSetTemplateResponse,
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

/** Sets the selected tone for drafting on the current session. */
export async function setDraftingTone(
  request: DraftingSetToneRequest,
): Promise<DraftingSetToneResponse> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/set-tone`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      // Scope the tone to the current editorial session.
      body: JSON.stringify({ ...request, sessionId: getConfig().sessionId }),
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting set-tone error: ${response.status}`);
  }
  return (await response.json()) as DraftingSetToneResponse;
}

/**
 * Saves one of the current session's draft versions as an unpublished
 * node. The backend resolves the drafted fields from its own draft
 * history, so the request only names the version.
 */
export async function saveDraftRevision(
  request: DraftingSaveRequest,
): Promise<DraftingSaveResponse> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/save`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      // Scope the save to the current editorial session.
      body: JSON.stringify({ ...request, sessionId: getConfig().sessionId }),
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting save error: ${response.status}`);
  }
  return (await response.json()) as DraftingSaveResponse;
}

/** Sets the selected drafting template on the current session. */
export async function setDraftingTemplate(
  request: DraftingSetTemplateRequest,
): Promise<DraftingSetTemplateResponse> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/set-template`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      // Scope the template to the current editorial session.
      body: JSON.stringify({ ...request, sessionId: getConfig().sessionId }),
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting set-template error: ${response.status}`);
  }
  return (await response.json()) as DraftingSetTemplateResponse;
}
