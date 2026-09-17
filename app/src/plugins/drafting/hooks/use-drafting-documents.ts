import { useCallback, useEffect, useRef, useState } from "react";
import { getConfig } from "@/config";
import { useAppStore } from "@/store";
import {
  addDraftingDocument,
  extractDraftingDocument,
  listDraftingDocuments,
  removeDraftingDocument,
} from "../api/drafting-api";
import { countUnsettled, isDocumentSettled } from "../document-status";
import type { DraftingDocument, DraftingDocumentCategory } from "../types";

export type { DraftingDocument } from "../types";

/** Interval between document list refreshes while a document is unsettled. */
export const DOCUMENT_POLL_INTERVAL_MS = 5000;

/**
 * Files accepted in one selection.
 *
 * A frontend-only guard against a selection of hundreds of files hitting
 * the backend at once; larger batches are simply selected in several
 * rounds.
 */
export const MAX_FILES_PER_SELECTION = 10;

/**
 * Fires processing for a document without blocking the caller.
 *
 * Not reported as pending work: cron finishes an abandoned document.
 */
function triggerExtraction(
  id: string,
  category: DraftingDocumentCategory,
): void {
  void extractDraftingDocument(id, category).catch(() => {});
}

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
 *
 * After an upload the server-side extraction is fired without waiting,
 * and the list is refreshed every few seconds until every document has
 * settled, so the status badges follow the pipeline.
 */
export function useDraftingDocuments(
  category: DraftingDocumentCategory = "context",
) {
  const draftingConfig = getConfig().pluginConfig.drafting ?? {};
  const documentsConfig = draftingConfig.documents as
    | { enabled?: boolean; extensions?: string[] }
    | undefined;
  const enabled = documentsConfig?.enabled ?? false;
  // File extensions the backend accepts, driving the upload control.
  const extensions = documentsConfig?.extensions ?? [];
  const [selected, setSelectedState] = useState<DraftingDocument[]>([]);
  // Mirror of the selected list for code running outside a render: the
  // refresh and request callbacks read the latest documents from here.
  const selectedRef = useRef<DraftingDocument[]>([]);
  // Pending refresh timer and the generation of the current polling run.
  // Every restart bumps the generation, so a refresh started under an
  // older one discards its answer instead of overwriting newer state.
  const pollTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pollGeneration = useRef(0);
  const [uploads, setUploads] = useState<DocumentUpload[]>([]);
  const [isSaving, setIsSaving] = useState(false);
  // TRUE until the initial list request settles; the panel blocks
  // interaction while it runs.
  const [isLoading, setIsLoading] = useState(enabled);
  // Failure of the initial list request, shown instead of the list.
  const [loadError, setLoadError] = useState<string | null>(null);
  // Why the last file selection was refused, cleared by the next one.
  const [selectionError, setSelectionError] = useState<string | null>(null);

  /** Writes the selected list, keeping the mirror in sync. */
  const setSelected = useCallback((documents: DraftingDocument[]) => {
    selectedRef.current = documents;
    setSelectedState(documents);
  }, []);

  /**
   * Ends the current polling run.
   *
   * Bumping the generation also silences a refresh already in flight: its
   * answer is dropped when it arrives.
   */
  const stopPolling = useCallback(() => {
    pollGeneration.current += 1;
    if (pollTimer.current) {
      clearTimeout(pollTimer.current);
      pollTimer.current = null;
    }
  }, []);

  /**
   * Starts a polling run that refreshes the list until nothing is in flight.
   *
   * Polling is read-only: it never triggers processing. A refresh that
   * fails is retried on the next tick. Called after every local change to
   * the list, so a stale refresh started before the change is discarded.
   */
  const pollUntilSettled = useCallback(() => {
    stopPolling();
    if (countUnsettled(selectedRef.current) === 0) {
      return;
    }
    const generation = pollGeneration.current;
    pollTimer.current = setTimeout(async () => {
      pollTimer.current = null;
      try {
        const documents = await listDraftingDocuments(category);
        if (generation !== pollGeneration.current) {
          return;
        }
        setSelected(documents);
      } catch {
        // Keep the current list; the next tick tries again.
        if (generation !== pollGeneration.current) {
          return;
        }
      }
      pollUntilSettled();
    }, DOCUMENT_POLL_INTERVAL_MS);
  }, [category, setSelected, stopPolling]);

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
          pollUntilSettled();
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
      stopPolling();
    };
  }, [enabled, category, setSelected, pollUntilSettled, stopPolling]);

  /**
   * Re-runs processing on a failed document and watches its progress.
   *
   * The document is shown as extracting right away: a refetch at this
   * point could still see the failed state, and failed counts as settled,
   * so polling would never start. Polling then follows the run, and the
   * action's own answer shortens the wait when it arrives first. An answer
   * arriving after polling saw the document settle is ignored: it is older
   * than what the list already shows.
   */
  async function retryDocument(id: string) {
    setSelected(
      selectedRef.current.map((item) =>
        item.id === id ? { ...item, status: "extracting" } : item,
      ),
    );
    void extractDraftingDocument(id, category)
      .then((status) => {
        setSelected(
          selectedRef.current.map((item) =>
            item.id === id && !isDocumentSettled(item.status)
              ? { ...item, status }
              : item,
          ),
        );
      })
      .catch(() => {});
    pollUntilSettled();
  }

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
      setSelected(selectedRef.current.filter((item) => item.id !== id));
      // Restart so a refresh started before the removal cannot bring the
      // document back.
      pollUntilSettled();
    } finally {
      setIsSaving(false);
      endDocumentWork();
    }
  }

  /**
   * Uploads every chosen file concurrently, one slot per file.
   *
   * Selections above MAX_FILES_PER_SELECTION are refused as a whole.
   *
   * Each file gets an uploading slot immediately. On success the slot is
   * replaced by the server-returned document; on failure it switches to
   * an error slot the user can dismiss.
   */
  async function uploadFiles(fileList: FileList | null) {
    if (!fileList) {
      return;
    }
    // Refuse the whole selection above the limit rather than uploading a
    // silent subset: the editor sees the message and selects again.
    if (fileList.length > MAX_FILES_PER_SELECTION) {
      setSelectionError(
        `Select up to ${MAX_FILES_PER_SELECTION} files at a time. Larger sets can be added in several rounds.`,
      );
      return;
    }
    setSelectionError(null);
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
          // A refresh may have listed the document already, with a newer
          // state than the upload response carries; keep that entry.
          if (!selectedRef.current.some((item) => item.id === document.id)) {
            setSelected([...selectedRef.current, document]);
          }
          setUploads((current) =>
            current.filter((upload) => upload.id !== slot.id),
          );
          triggerExtraction(document.id, category);
          pollUntilSettled();
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
    extensions,
    selected,
    uploads,
    count: selected.length,
    processingCount: countUnsettled(selected),
    isSaving,
    isLoading,
    loadError,
    selectionError,
    removeDocument,
    retryDocument,
    uploadFiles,
    dismissUpload,
  };
}
