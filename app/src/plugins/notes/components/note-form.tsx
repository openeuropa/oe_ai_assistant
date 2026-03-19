/**
 * Note form.
 *
 * Handles both creating a new note and editing an existing one.
 * If a `noteId` prop is provided, the form fetches the note via
 * TanStack Query and pre-populates for editing. Validates that
 * the title is not empty before submitting.
 */

import { type FormEvent, useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { FieldError } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { useNote } from "../hooks/use-notes";

interface NoteFormProps {
  /** ID of the note to edit (omit for create mode). */
  noteId?: string;
  /** Called when the form is submitted with valid data. */
  onSubmit: (data: { title: string; content: string }) => void;
  /** Called when the user cancels editing. */
  onCancel: () => void;
  /** Whether the mutation is in progress. */
  isPending: boolean;
}

export function NoteForm({
  noteId,
  onSubmit,
  onCancel,
  isPending,
}: NoteFormProps) {
  // Fetch the note when editing. Disabled when creating (no noteId).
  const { data: note, isLoading: isLoadingNote } = useNote(noteId ?? "", {
    enabled: !!noteId,
  });

  const [title, setTitle] = useState("");
  const [content, setContent] = useState("");
  const [titleError, setTitleError] = useState<string | null>(null);

  // Populate form fields when the note data arrives.
  useEffect(() => {
    if (note) {
      setTitle(note.title);
      setContent(note.content);
    } else if (!noteId) {
      // Create mode: reset to empty.
      setTitle("");
      setContent("");
    }
    setTitleError(null);
  }, [note, noteId]);

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const trimmedTitle = title.trim();
    if (trimmedTitle.length === 0) {
      setTitleError("Title is required");
      return;
    }
    setTitleError(null);
    onSubmit({ title: trimmedTitle, content: content.trim() });
  }

  // Show a loading state while fetching the note for editing.
  if (noteId && isLoadingNote) {
    return <p className="text-sm text-gray-500">Loading note...</p>;
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {/* Title field */}
      <div>
        <Label htmlFor="note-title">Title</Label>
        <Input
          id="note-title"
          type="text"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          disabled={isPending}
          className="mt-1"
        />
        {titleError && <FieldError>{titleError}</FieldError>}
      </div>

      {/* Content field */}
      <div>
        <Label htmlFor="note-content">Content</Label>
        <Textarea
          id="note-content"
          value={content}
          onChange={(e) => setContent(e.target.value)}
          rows={4}
          disabled={isPending}
          className="mt-1"
        />
      </div>

      {/* Action buttons */}
      <div className="flex gap-2">
        <Button type="submit" disabled={isPending}>
          {isPending ? "Saving..." : noteId ? "Update" : "Create"}
        </Button>
        <Button
          type="button"
          variant="outline"
          onClick={onCancel}
          disabled={isPending}
        >
          Cancel
        </Button>
      </div>
    </form>
  );
}
