import { useState } from "react";

/** A reference document that can ground the next draft. */
export interface DraftingDocument {
  id: string;
  title: string;
  /** Short descriptor, e.g. "PDF - 240 KB". */
  meta: string;
}

// Mock document list until a backend document service exists.
const INITIAL_SELECTED: DraftingDocument[] = [
  {
    id: "eu-ai-act-brief",
    title: "EU AI Act briefing note.pdf",
    meta: "PDF - 240 KB",
  },
  {
    id: "stakeholder-comments",
    title: "Stakeholder comments.docx",
    meta: "DOCX - 96 KB",
  },
];

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
 * TODO: Replace the mock lists and client-only mutations with a backend
 * document service once documents are uploaded and persisted server side.
 */
export function useDraftingDocuments() {
  const [selected, setSelected] =
    useState<DraftingDocument[]>(INITIAL_SELECTED);

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
    selected,
    count: selected.length,
    removeDocument,
    uploadFiles,
  };
}
