import { useState } from "react";
import { getConfig } from "@/config";
import { readConfigOptions } from "../config-options";
import type { DraftingDocument } from "../types";

export type { DraftingDocument } from "../types";

/** Formats a byte size into a short human-readable string. */
function formatFileSize(size: number): string {
  if (size < 1024) {
    return `${size} B`;
  }
  if (size < 1024 * 1024) {
    return `${Math.round(size / 1024)} KB`;
  }
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Owns the reference documents state for drafting.
 *
 * The initial list comes from the host config, so it works as soon as the
 * backend sends documents. Uploads and removals, however, only mutate local
 * state.
 *
 * TODO: Persist uploads and removals via a backend document service; until
 * then those changes are lost on reload.
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

  /** Removes a document from the list. */
  function removeDocument(id: string) {
    setSelected((current) => current.filter((item) => item.id !== id));
  }

  /** Appends uploaded files to the selected list. */
  function uploadFiles(fileList: FileList | null) {
    if (!fileList) {
      return;
    }
    const uploaded = Array.from(fileList).map((file) => ({
      id: `${file.name}-${file.lastModified}`,
      title: file.name,
      meta: `${file.type || "File"} - ${formatFileSize(file.size)}`,
    }));
    setSelected((current) => [...current, ...uploaded]);
  }

  return {
    enabled,
    selected,
    count: selected.length,
    removeDocument,
    uploadFiles,
  };
}
