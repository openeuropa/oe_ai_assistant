/**
 * Notes plugin root component.
 *
 * Dev-only CRUD notepad for stress-testing the architecture.
 * Exercises TanStack Query queries, mutations, cache invalidation,
 * and optimistic updates. View state (list/create/edit) is managed
 * through the plugin's Zustand store slice so it is accessible to
 * other plugins and the shell.
 */

import { Button } from "@/components/ui/button";
import { NoteForm } from "./components/note-form";
import { NotesList } from "./components/notes-list";
import {
  useCreateNote,
  useDeleteNote,
  useNotes,
  useUpdateNote,
} from "./hooks/use-notes";
import { setNotesState, useNotesSlice } from "./store";

export default function NotesRoot() {
  // Read view state from the plugin's store slice.
  const { view } = useNotesSlice();
  const { data: notes, isLoading, error } = useNotes();
  const createMutation = useCreateNote();
  const updateMutation = useUpdateNote();
  const deleteMutation = useDeleteNote();

  /** Switch back to the list after a successful mutation. */
  function goToList() {
    setNotesState({ view: { mode: "list" } });
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6 p-8">
      <p className="text-sm text-gray-500">
        CRUD notepad backed by an in-memory mock API.
      </p>

      {/* List view */}
      {view.mode === "list" && (
        <>
          <Button
            type="button"
            onClick={() => setNotesState({ view: { mode: "create" } })}
          >
            New note
          </Button>
          <NotesList
            notes={notes ?? []}
            isLoading={isLoading}
            error={error}
            onEdit={(note) =>
              setNotesState({ view: { mode: "edit", noteId: note.id } })
            }
            onDelete={(id) => deleteMutation.mutate(id)}
          />
        </>
      )}

      {/* Create view */}
      {view.mode === "create" && (
        <NoteForm
          onSubmit={(data) =>
            createMutation.mutate(data, { onSuccess: goToList })
          }
          onCancel={goToList}
          isPending={createMutation.isPending}
        />
      )}

      {/* Edit view */}
      {view.mode === "edit" && (
        <NoteForm
          noteId={view.noteId}
          onSubmit={(data) =>
            updateMutation.mutate(
              { noteId: view.noteId, input: data },
              { onSuccess: goToList },
            )
          }
          onCancel={goToList}
          isPending={updateMutation.isPending}
        />
      )}
    </div>
  );
}
