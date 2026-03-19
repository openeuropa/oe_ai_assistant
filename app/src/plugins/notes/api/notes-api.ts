/**
 * Notes API client (RPC-style).
 *
 * Each function maps to a single RPC action: POST with the verb in
 * the URL path and all parameters in the JSON request body.
 */

import type { components } from "@/api/schema";
import { getConfig } from "@/config";

type Note = components["schemas"]["Note"];

/** Build the full URL for a notes action using the configured base. */
function notesUrl(action: string): string {
  return `${getConfig().apiBaseUrl}/plugins/notes/${action}`;
}

/** POST /plugins/notes/list : fetch all notes. */
export async function fetchNotes(): Promise<Note[]> {
  const response = await fetch(notesUrl("list"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({}),
  });
  if (!response.ok)
    throw new Error(`Failed to fetch notes: ${response.status}`);
  return response.json() as Promise<Note[]>;
}

/** POST /plugins/notes/get : fetch a single note. */
export async function fetchNote(noteId: string): Promise<Note> {
  const response = await fetch(notesUrl("get"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ noteId }),
  });
  if (!response.ok) throw new Error(`Failed to fetch note: ${response.status}`);
  return response.json() as Promise<Note>;
}

/** POST /plugins/notes/create : create a new note. */
export async function createNote(input: {
  title: string;
  content: string;
}): Promise<Note> {
  const response = await fetch(notesUrl("create"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(input),
  });
  if (!response.ok)
    throw new Error(`Failed to create note: ${response.status}`);
  return response.json() as Promise<Note>;
}

/** POST /plugins/notes/update : update an existing note. */
export async function updateNote(
  noteId: string,
  input: { title: string; content: string },
): Promise<Note> {
  const response = await fetch(notesUrl("update"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ noteId, ...input }),
  });
  if (!response.ok)
    throw new Error(`Failed to update note: ${response.status}`);
  return response.json() as Promise<Note>;
}

/** POST /plugins/notes/delete : delete a note. */
export async function deleteNote(noteId: string): Promise<void> {
  const response = await fetch(notesUrl("delete"), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ noteId }),
  });
  if (!response.ok)
    throw new Error(`Failed to delete note: ${response.status}`);
}
