/**
 * DraftCard component.
 *
 * Displays a summary of a draft tool-call result as a clickable card in the
 * chat thread. The card body is a button that calls onOpen, which repopulates
 * the artifact panel. Documents are listed as link-style buttons that open a
 * detail dialog; their click events are stopped from bubbling to the card.
 */

import { Check, PenLine } from "lucide-react";
import { useState } from "react";
import type { DraftContext, DraftDocumentSnapshot } from "../draft-result";
import {
  categoryBadgeLabel,
  DocumentDetailsDialog,
} from "./document-details-dialog";

/** Props for DraftCard. */
export interface DraftCardProps {
  /** Numeric draft version, or null for a legacy draft without version info. */
  version: number | null;
  /**
   * The editorial context captured when the draft was generated. Null for
   * legacy drafts that pre-date context tracking.
   */
  context: DraftContext | null;
  /** The drafted fields produced by the AI. */
  fields: Record<string, unknown>;
  /** Called when the user clicks the card body to view this draft. */
  onOpen: () => void;
}

/**
 * Returns the card title based on the draft version.
 *
 * "Draft N" when a numeric version is available, plain "Draft" otherwise.
 */
function draftTitle(version: number | null): string {
  return version !== null ? `Draft ${version}` : "Draft";
}

/**
 * Clickable card that summarises a draft tool-call result.
 *
 * Styled to match ToolCallCard in tool-uis.tsx for visual consistency in the
 * chat thread. A green check icon signals that the draft completed
 * successfully. Documents open a detail dialog without triggering onOpen.
 */
export function DraftCard({
  version,
  context,
  fields,
  onOpen,
}: DraftCardProps) {
  // The currently selected document for the detail dialog; null means closed.
  const [selectedDocument, setSelectedDocument] =
    useState<DraftDocumentSnapshot | null>(null);

  const fieldCount = Object.keys(fields).length;
  const documents = context?.documents ?? [];
  const tone = context?.tone ?? null;
  const template = context?.template ?? null;

  return (
    <>
      {/* Card body is a button so the whole surface is clickable. */}
      <button
        type="button"
        className="my-4 flex w-full flex-col items-start gap-0 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-left cursor-pointer transition-colors hover:border-gray-300 hover:bg-gray-50"
        onClick={onOpen}
      >
        {/* Header row: status icon, PenLine icon, and title. */}
        <div className="flex w-full items-start gap-3">
          <div className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center">
            <Check size={16} className="text-green-500" />
          </div>

          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5">
              <PenLine size={14} className="shrink-0 text-gray-400" />
              <span className="text-sm font-medium text-gray-700">
                {draftTitle(version)}
              </span>
            </div>

            {/* Field count subline. */}
            {fieldCount > 0 && (
              <p className="mt-0.5 text-xs text-gray-400">
                {fieldCount} {fieldCount === 1 ? "field" : "fields"} - click to
                open
              </p>
            )}

            {/* Tone row: only when the context includes a tone. */}
            {tone && (
              <p className="mt-1 text-xs text-gray-500">
                <span className="font-medium">Tone:</span> {tone.label}
              </p>
            )}

            {/* Template row: only when the context includes a template. */}
            {template && (
              <p className="mt-0.5 text-xs text-gray-500">
                <span className="font-medium">Template:</span> {template.label}
              </p>
            )}

            {/* Documents section: only when at least one document was used. */}
            {documents.length > 0 && (
              <div className="mt-2">
                <p className="text-xs font-medium text-gray-500">Documents</p>
                <ul className="mt-1 space-y-0.5">
                  {documents.map((doc) => (
                    <li key={doc.id} className="flex items-center gap-1.5">
                      {/* Document link stops propagation so onOpen is not called. */}
                      <button
                        type="button"
                        className="cursor-pointer text-xs text-blue-600 underline-offset-2 hover:underline"
                        onClick={(event) => {
                          event.stopPropagation();
                          setSelectedDocument(doc);
                        }}
                      >
                        {doc.title}
                      </button>

                      {/* Category badge next to the document link. */}
                      <span className="rounded-full border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-xs text-gray-400">
                        {categoryBadgeLabel(doc.category)}
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        </div>
      </button>

      {/* Document details dialog: controlled by selectedDocument state. */}
      <DocumentDetailsDialog
        document={selectedDocument}
        onClose={() => setSelectedDocument(null)}
      />
    </>
  );
}
