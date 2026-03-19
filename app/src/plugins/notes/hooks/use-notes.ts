/**
 * TanStack Query hooks for the notes RPC API.
 *
 * Provides queries for listing/fetching notes and mutations for
 * create/update/delete. The delete mutation uses optimistic updates:
 * the note is removed from the cache immediately and rolled back
 * if the mutation fails.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  createNote,
  deleteNote,
  fetchNote,
  fetchNotes,
  updateNote,
} from "../api/notes-api";
import type { Note, NotesCreateRequest } from "../types";

/** Query key prefix for all notes queries. */
const NOTES_KEY = ["dev", "notes"] as const;

/** Fetch all notes. */
export function useNotes() {
  return useQuery({
    queryKey: NOTES_KEY,
    queryFn: fetchNotes,
  });
}

/** Fetch a single note by ID. Accepts an optional `enabled` flag. */
export function useNote(id: string, options?: { enabled?: boolean }) {
  return useQuery({
    queryKey: [...NOTES_KEY, id],
    queryFn: () => fetchNote(id),
    enabled: options?.enabled,
  });
}

/** Create a new note. Invalidates the notes list on success. */
export function useCreateNote() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: NotesCreateRequest) => createNote(input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: NOTES_KEY });
    },
  });
}

/** Update an existing note. Invalidates both list and detail caches. */
export function useUpdateNote() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      noteId,
      input,
    }: {
      noteId: string;
      input: { title: string; content: string };
    }) => updateNote(noteId, input),
    onSuccess: (_data, { noteId }) => {
      queryClient.invalidateQueries({ queryKey: NOTES_KEY });
      queryClient.invalidateQueries({ queryKey: [...NOTES_KEY, noteId] });
    },
  });
}

/**
 * Delete a note with optimistic update.
 * The note is removed from the cached list immediately.
 * If the mutation fails, the previous list is restored.
 */
export function useDeleteNote() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (noteId: string) => deleteNote(noteId),
    onMutate: async (noteId) => {
      // Cancel in-flight fetches so they don't overwrite our optimistic update.
      await queryClient.cancelQueries({ queryKey: NOTES_KEY });

      // Snapshot the current list for rollback.
      const previous = queryClient.getQueryData<Note[]>(NOTES_KEY);

      // Optimistically remove the note from the cached list.
      queryClient.setQueryData<Note[]>(NOTES_KEY, (old) =>
        old ? old.filter((n) => n.id !== noteId) : [],
      );

      return { previous };
    },
    onError: (_err, _noteId, context) => {
      // Roll back to the previous list on failure.
      if (context?.previous) {
        queryClient.setQueryData(NOTES_KEY, context.previous);
      }
    },
    onSettled: () => {
      // Refetch to ensure the cache is in sync with the "server".
      queryClient.invalidateQueries({ queryKey: NOTES_KEY });
    },
  });
}
