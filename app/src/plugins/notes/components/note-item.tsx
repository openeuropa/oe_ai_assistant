/**
 * Single note display.
 *
 * Shows a note's title, content preview, and timestamp, with
 * edit and delete action buttons.
 */

import type { Note } from "../types";

interface NoteItemProps {
  note: Note;
  /** Called when the user clicks "Edit". */
  onEdit: (note: Note) => void;
  /** Called when the user clicks "Delete". */
  onDelete: (id: string) => void;
}

export function NoteItem({ note, onEdit, onDelete }: NoteItemProps) {
  return (
    <div className="flex items-start justify-between rounded-md border border-gray-200 p-4">
      <div className="min-w-0 flex-1">
        <h3 className="text-sm font-medium text-gray-900">{note.title}</h3>
        {note.content && (
          <p className="mt-1 truncate text-sm text-gray-500">{note.content}</p>
        )}
        <p className="mt-1 text-xs text-gray-400">
          Updated {new Date(note.updatedAt).toLocaleString()}
        </p>
      </div>
      <div className="ml-4 flex shrink-0 gap-1">
        <button
          type="button"
          onClick={() => onEdit(note)}
          className="rounded px-2 py-1 text-xs text-blue-600 hover:bg-blue-50"
        >
          Edit
        </button>
        <button
          type="button"
          onClick={() => onDelete(note.id)}
          className="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50"
        >
          Delete
        </button>
      </div>
    </div>
  );
}
