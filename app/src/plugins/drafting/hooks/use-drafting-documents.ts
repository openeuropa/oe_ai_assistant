import { useState } from "react";
import { getConfig } from "@/config";
import {
  addDraftingDocument,
  removeDraftingDocument,
} from "../api/drafting-api";
import { readConfigOptions } from "../config-options";
import type { DraftingDocument } from "../types";

export type { DraftingDocument } from "../types";

/**
 * Owns the reference documents state for drafting.
 *
 * The initial list comes from the host config. Uploads and removals are
 * persisted immediately through the drafting document endpoints for the
 * context category.
 */
export function useDraftingDocuments() {
  const draftingConfig = getConfig().pluginConfig.drafting ?? {};
  const documentsConfig = draftingConfig.documents as
    | { enabled?: boolean; options?: unknown }
    | undefined;
  const enabled = documentsConfig?.enabled ?? false;
  const [selected, setSelected] = useState<DraftingDocument[]>(() =>
    readConfigOptions<DraftingDocument>(documentsConfig?.options),
  );
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /** Removes a document from the persisted context list. */
  async function removeDocument(id: string) {
    setIsSaving(true);
    setError(null);
    try {
      await removeDraftingDocument(id, "context");
      setSelected((current) => current.filter((item) => item.id !== id));
    } catch (exception) {
      setError(
        exception instanceof Error
          ? exception.message
          : "The document could not be removed.",
      );
      throw exception;
    } finally {
      setIsSaving(false);
    }
  }

  /** Uploads files and appends the server-returned documents in order. */
  async function uploadFiles(fileList: FileList | null) {
    if (!fileList) {
      return;
    }
    setIsSaving(true);
    setError(null);
    try {
      const uploaded: DraftingDocument[] = [];
      for (const file of Array.from(fileList)) {
        uploaded.push(await addDraftingDocument(file, "context"));
      }
      setSelected((current) => [...current, ...uploaded]);
    } catch (exception) {
      setError(
        exception instanceof Error
          ? exception.message
          : "The document could not be uploaded.",
      );
      throw exception;
    } finally {
      setIsSaving(false);
    }
  }

  return {
    enabled,
    selected,
    count: selected.length,
    isSaving,
    error,
    removeDocument,
    uploadFiles,
  };
}
