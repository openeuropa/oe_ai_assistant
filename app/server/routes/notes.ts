/**
 * Notes RPC endpoints (dev-only).
 *
 * All actions are POST with the verb in the URL path:
 *   POST /api/plugins/notes/list
 *   POST /api/plugins/notes/get
 *   POST /api/plugins/notes/create
 *   POST /api/plugins/notes/update
 *   POST /api/plugins/notes/delete
 *
 * noteId (where needed) is in the request body, not the URL.
 * All responses use 200 (RPC convention -- no 201/204).
 *
 * All data is stored in memory. Restarting the server resets to
 * the seeded sample data. Each response is delayed by a random
 * 200-500ms to simulate real network latency.
 */

import { Router } from "express";

export const notesRouter = Router();

/** Shape of a note stored in memory. */
interface Note {
  id: string;
  title: string;
  content: string;
  createdAt: string;
  updatedAt: string;
}

// -- In-memory store, seeded with sample data --

const store = new Map<string, Note>();

const now = new Date().toISOString();
store.set("seed-1", {
  id: "seed-1",
  title: "Welcome note",
  content: "This is a sample note seeded by the dev API server.",
  createdAt: now,
  updatedAt: now,
});
store.set("seed-2", {
  id: "seed-2",
  title: "Architecture notes",
  content:
    "The app uses a plugin-based architecture with Zustand for state and TanStack Query for server state.",
  createdAt: now,
  updatedAt: now,
});

/** Generates a simple unique ID. */
function generateId(): string {
  return Math.random().toString(36).slice(2, 10);
}

/** Random delay between 200-500ms to simulate network latency. */
function randomDelay(): number {
  return Math.floor(Math.random() * 300) + 200;
}

/** Wraps an Express response in a random delay. */
function delayed(fn: () => void): void {
  setTimeout(fn, randomDelay());
}

// -- Route handlers (all POST, RPC-style) --

/** POST /list : list all notes, sorted newest first. */
notesRouter.post("/list", (_req, res) => {
  delayed(() => {
    const notes = Array.from(store.values()).sort(
      (a, b) =>
        new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime(),
    );
    res.json(notes);
  });
});

/** POST /get : get a single note by ID from the request body. */
notesRouter.post("/get", (req, res) => {
  const { noteId } = req.body as { noteId?: string };

  if (!noteId) {
    res
      .status(400)
      .json({ code: "bad_request", message: "noteId is required" });
    return;
  }

  delayed(() => {
    const note = store.get(noteId);
    if (!note) {
      res.status(404).json({ code: "not_found", message: "Note not found" });
      return;
    }
    res.json(note);
  });
});

/** POST /create : create a new note. */
notesRouter.post("/create", (req, res) => {
  const { title, content } = req.body as {
    title?: string;
    content?: string;
  };

  if (!title || typeof title !== "string" || title.trim().length === 0) {
    res.status(400).json({ code: "bad_request", message: "title is required" });
    return;
  }

  delayed(() => {
    const id = generateId();
    const timestamp = new Date().toISOString();
    const note: Note = {
      id,
      title: title.trim(),
      content: (content ?? "").trim(),
      createdAt: timestamp,
      updatedAt: timestamp,
    };
    store.set(id, note);
    res.json(note);
  });
});

/** POST /update : update an existing note. */
notesRouter.post("/update", (req, res) => {
  const { noteId, title, content } = req.body as {
    noteId?: string;
    title?: string;
    content?: string;
  };

  if (!noteId) {
    res
      .status(400)
      .json({ code: "bad_request", message: "noteId is required" });
    return;
  }

  if (!title || typeof title !== "string" || title.trim().length === 0) {
    res.status(400).json({ code: "bad_request", message: "title is required" });
    return;
  }

  delayed(() => {
    const existing = store.get(noteId);
    if (!existing) {
      res.status(404).json({ code: "not_found", message: "Note not found" });
      return;
    }

    const updated: Note = {
      ...existing,
      title: title.trim(),
      content: (content ?? "").trim(),
      updatedAt: new Date().toISOString(),
    };
    store.set(noteId, updated);
    res.json(updated);
  });
});

/** POST /delete : delete a note. */
notesRouter.post("/delete", (req, res) => {
  const { noteId } = req.body as { noteId?: string };

  if (!noteId) {
    res
      .status(400)
      .json({ code: "bad_request", message: "noteId is required" });
    return;
  }

  delayed(() => {
    if (!store.has(noteId)) {
      res.status(404).json({ code: "not_found", message: "Note not found" });
      return;
    }
    store.delete(noteId);
    res.json({});
  });
});
