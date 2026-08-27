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

import type { components } from "@/api/schema";
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

type DraftingCategory = components["schemas"]["DraftingDocumentCategory"];
type DraftingDocument = components["schemas"]["DraftingDocument"];
type DraftingAddDocumentResponse =
  components["schemas"]["DraftingAddDocumentResponse"];
type DraftingListDocumentsResponse =
  components["schemas"]["DraftingListDocumentsResponse"];
type DraftingRemoveDocumentResponse =
  components["schemas"]["DraftingRemoveDocumentResponse"];

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

/** Uploads a document to the current drafting session. */
export async function addDraftingDocument(
  file: File,
  category: DraftingCategory = "context",
): Promise<DraftingDocument> {
  const formData = new FormData();
  formData.append("sessionId", getConfig().sessionId);
  formData.append("category", category);
  formData.append("file", file);

  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/add-document`,
    {
      method: "POST",
      credentials: "include",
      body: formData,
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting add-document error: ${response.status}`);
  }
  const body = (await response.json()) as DraftingAddDocumentResponse;
  return body.document;
}

/** Lists documents referenced by the current drafting session. */
export async function listDraftingDocuments(
  category: DraftingCategory = "context",
): Promise<DraftingDocument[]> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/list-documents`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ sessionId: getConfig().sessionId, category }),
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting list-documents error: ${response.status}`);
  }
  const body = (await response.json()) as DraftingListDocumentsResponse;
  return body.documents;
}

/** Removes a document from the current drafting session. */
export async function removeDraftingDocument(
  documentId: string,
): Promise<DraftingRemoveDocumentResponse> {
  const response = await fetch(
    `${getConfig().apiBaseUrl}/plugins/drafting/remove-document`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({
        sessionId: getConfig().sessionId,
        documentId,
      }),
    },
  );
  if (!response.ok) {
    throw new Error(`Drafting remove-document error: ${response.status}`);
  }
  return (await response.json()) as DraftingRemoveDocumentResponse;
}
