/**
 * Notes list.
 *
 * Fetches all notes via TanStack Query and renders them as a
 * list of NoteItem components. Handles loading, error, and
 * empty states.
 */

import type { Note } from "../types";
import { NoteItem } from "./note-item";

interface NotesListProps {
  /** The fetched notes array. */
  notes: Note[];
  /** Whether the query is loading. */
  isLoading: boolean;
  /** Error from the query, if any. */
  error: Error | null;
  /** Called when the user clicks "Edit" on a note. */
  onEdit: (note: Note) => void;
  /** Called when the user clicks "Delete" on a note. */
  onDelete: (id: string) => void;
}

export function NotesList({
  notes,
  isLoading,
  error,
  onEdit,
  onDelete,
}: NotesListProps) {
  if (isLoading) {
    return <p className="text-sm text-gray-500">Loading notes...</p>;
  }

  if (error) {
    return (
      <p className="text-sm text-red-600">
        Failed to load notes: {error.message}
      </p>
    );
  }

  if (notes.length === 0) {
    return <p className="text-sm text-gray-500">No notes yet. Create one!</p>;
  }

  return (
    <div className="space-y-2">
      {notes.map((note) => (
        <NoteItem
          key={note.id}
          note={note}
          onEdit={onEdit}
          onDelete={onDelete}
        />
      ))}
    </div>
  );
}
