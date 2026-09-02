import { useEffect, useState } from "react";
import { getConfig } from "@/config";
import { useAppStore } from "@/store";
import {
  addDraftingDocument,
  listDraftingDocuments,
  removeDraftingDocument,
} from "../api/drafting-api";
import type { DraftingDocument, DraftingDocumentCategory } from "../types";

export type { DraftingDocument } from "../types";

/**
 * In-flight document requests, counted across concurrent operations.
 *
 * Module-level so parallel uploads and removals share one counter: the
 * shell exit guard stays blocked until the last request settles, instead
 * of unblocking when any single request finishes.
 */
let pendingDocumentRequests = 0;

/** Reports one more document request to the shell exit guard. */
function beginDocumentWork(): void {
  pendingDocumentRequests += 1;
  useAppStore.getState().setPendingWork("drafting:documents", true);
}

/** Settles one document request, releasing the guard on the last one. */
function endDocumentWork(): void {
  pendingDocumentRequests = Math.max(0, pendingDocumentRequests - 1);
  if (pendingDocumentRequests === 0) {
    useAppStore.getState().setPendingWork("drafting:documents", false);
  }
}

/** A file upload in flight or failed, shown as a slot card in the panel. */
export interface DocumentUpload {
  /** Client-side slot id; the server id only exists after success. */
  id: string;
  /** Original file name shown on the slot card. */
  title: string;
  /** File size in bytes. */
  size: number;
  /** Uploading shows the progress bar; error shows the message. */
  status: "uploading" | "error";
  /** Endpoint error message when the upload failed. */
  error?: string;
}

/**
 * Owns the session documents state for one category.
 *
 * The host config only gates the panel; the document list itself is
 * fetched through the list-documents action once the app has booted,
 * so the backend bootstrap carries no document data. Uploads and
 * removals are persisted immediately through the drafting document
 * endpoints. Uploads run concurrently: each file gets its own slot,
 * so more files can be added while earlier uploads still run.
 */
export function useDraftingDocuments(
  category: DraftingDocumentCategory = "context",
) {
  const draftingConfig = getConfig().pluginConfig.drafting ?? {};
  const documentsConfig = draftingConfig.documents as
    | { enabled?: boolean }
    | undefined;
  const enabled = documentsConfig?.enabled ?? false;
  const [selected, setSelected] = useState<DraftingDocument[]>([]);
  const [uploads, setUploads] = useState<DocumentUpload[]>([]);
  const [isSaving, setIsSaving] = useState(false);
  // TRUE until the initial list request settles; the panel blocks
  // interaction while it runs.
  const [isLoading, setIsLoading] = useState(enabled);
  // Failure of the initial list request, shown instead of the list.
  const [loadError, setLoadError] = useState<string | null>(null);

  // Fetch the persisted documents once after boot.
  useEffect(() => {
    if (!enabled) {
      return;
    }
    let cancelled = false;
    listDraftingDocuments(category)
      .then((documents) => {
        if (!cancelled) {
          setSelected(documents);
        }
      })
      .catch((exception: unknown) => {
        if (!cancelled) {
          setLoadError(
            exception instanceof Error
              ? exception.message
              : "The documents could not be loaded.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [enabled, category]);

  /**
   * Removes a document from the persisted list.
   *
   * Failures are rethrown without touching the upload slots: the removal
   * confirmation dialog owns their display.
   */
  async function removeDocument(id: string) {
    setIsSaving(true);
    beginDocumentWork();
    try {
      await removeDraftingDocument(id, category);
      setSelected((current) => current.filter((item) => item.id !== id));
    } finally {
      setIsSaving(false);
      endDocumentWork();
    }
  }

  /**
   * Uploads every chosen file concurrently, one slot per file.
   *
   * Each file gets an uploading slot immediately. On success the slot is
   * replaced by the server-returned document; on failure it switches to
   * an error slot the user can dismiss.
   */
  async function uploadFiles(fileList: FileList | null) {
    if (!fileList) {
      return;
    }
    const entries = Array.from(fileList).map((file) => ({
      file,
      slot: {
        id: crypto.randomUUID(),
        title: file.name,
        size: file.size,
        status: "uploading",
      } satisfies DocumentUpload,
    }));
    setUploads((current) => [
      ...current,
      ...entries.map((entry) => entry.slot),
    ]);

    await Promise.all(
      entries.map(async ({ file, slot }) => {
        beginDocumentWork();
        try {
          const document = await addDraftingDocument(file, category);
          setSelected((current) => [...current, document]);
          setUploads((current) =>
            current.filter((upload) => upload.id !== slot.id),
          );
        } catch (exception) {
          setUploads((current) =>
            current.map((upload) =>
              upload.id === slot.id
                ? {
                    ...upload,
                    status: "error",
                    error:
                      exception instanceof Error
                        ? exception.message
                        : "The document could not be uploaded.",
                  }
                : upload,
            ),
          );
        } finally {
          endDocumentWork();
        }
      }),
    );
  }

  /** Drops a failed upload slot from the panel. */
  function dismissUpload(id: string) {
    setUploads((current) => current.filter((upload) => upload.id !== id));
  }

  return {
    enabled,
    selected,
    uploads,
    count: selected.length,
    isSaving,
    isLoading,
    loadError,
    removeDocument,
    uploadFiles,
    dismissUpload,
  };
}
