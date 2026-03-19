/**
 * Types for the Notes dev plugin.
 *
 * Re-exported from the generated OpenAPI schema so there is a single
 * source of truth. Plugin code imports from here for convenience.
 */

import type { components } from "@/api/schema";

/** A single note stored in the mock backend. */
export type Note = components["schemas"]["Note"];

/** Request body for creating a new note. */
export type NotesCreateRequest = components["schemas"]["NotesCreateRequest"];

/** Request body for updating an existing note. */
export type NotesUpdateRequest = components["schemas"]["NotesUpdateRequest"];
