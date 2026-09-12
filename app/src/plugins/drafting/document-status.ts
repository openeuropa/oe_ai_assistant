/**
 * Presentation facts for the extraction status of a session document.
 *
 * Kept out of the component so the mapping is unit-tested and the badge
 * stays a thin renderer.
 */

import type { DraftingDocumentStatus } from "./types";

/** Visual tone of a status badge. */
export type DocumentStatusTone = "neutral" | "progress" | "success" | "danger";

/** What the badge shows for one status. */
export interface DocumentStatusDescription {
  /** Short label shown in the badge. */
  label: string;
  /** Colour family of the badge. */
  tone: DocumentStatusTone;
  /** TRUE until the document has settled: the pipeline still owns it. */
  busy: boolean;
}

const DESCRIPTIONS: Record<DraftingDocumentStatus, DocumentStatusDescription> =
  {
    scheduled: { label: "Scheduled", tone: "progress", busy: true },
    extracting: { label: "Extracting", tone: "progress", busy: true },
    extracted: { label: "Extracted", tone: "progress", busy: true },
    summarizing: { label: "Summarizing", tone: "progress", busy: true },
    done: { label: "Ready", tone: "success", busy: false },
    error: { label: "Failed", tone: "danger", busy: false },
  };

/** Returns the label, tone and busy flag of a status. */
export function describeDocumentStatus(
  status: DraftingDocumentStatus,
): DocumentStatusDescription {
  return DESCRIPTIONS[status];
}

/** Whether a status is final: nothing more will change without a retry. */
export function isDocumentSettled(status: DraftingDocumentStatus): boolean {
  return status === "done" || status === "error";
}

/** Counts the documents the pipeline still owns. */
export function countUnsettled(
  documents: { status: DraftingDocumentStatus }[],
): number {
  return documents.filter((document) => !isDocumentSettled(document.status))
    .length;
}
