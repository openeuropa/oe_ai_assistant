/**
 * DraftCard component.
 *
 * Displays a summary of a draft tool-call result as a clickable card in the
 * chat thread. The card body is a button that calls onOpen, which repopulates
 * the artifact panel. Documents are listed as link-style buttons that open a
 * detail dialog; their click events are stopped from bubbling to the card.
 */

import {
  Check,
  FileText,
  Film,
  Image,
  LayoutTemplate,
  Megaphone,
  PenLine,
} from "lucide-react";
import { useState } from "react";
import type { DraftContext, DraftDocumentSnapshot } from "../draft-result";
import { DocumentDetailsDialog } from "./document-details-dialog";

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
 * Picks the media-kind icon for a document link.
 *
 * The kind is inferred from the mime type in the descriptor's meta when one
 * is present: images and videos get their own icon, everything else shows as
 * a generic document. The exact type is never spelled out in the UI.
 */
function documentKindIcon(doc: DraftDocumentSnapshot): typeof FileText {
  const meta = doc.meta;
  const mime =
    typeof meta === "object" && meta !== null && "mime" in meta
      ? String((meta as Record<string, unknown>).mime)
      : "";
  if (mime.startsWith("image/")) {
    return Image;
  }
  if (mime.startsWith("video/")) {
    return Film;
  }
  return FileText;
}

/**
 * One row of the provenance table: an iconed bold label and its value.
 *
 * Rendered with div cells inside the card's grid because the card body is a
 * button, whose content model does not allow a real table element.
 */
function ProvenanceRow({
  icon: Icon,
  label,
  children,
}: {
  icon: typeof Megaphone;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <>
      <div className="flex items-center gap-1 font-semibold text-gray-600">
        <Icon size={12} className="shrink-0 text-gray-400" aria-hidden="true" />
        {label}
      </div>
      <div className="min-w-0 text-gray-500">{children}</div>
    </>
  );
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

  // Group the document snapshots by their category so the table shows one
  // row per kind instead of a badge after every name. Anything that is not
  // a publishable asset counts as briefing material so no document is ever
  // silently dropped.
  const publishableAssets = documents.filter(
    (d) => d.category === "publishable",
  );
  const briefingDocuments = documents.filter(
    (d) => d.category !== "publishable",
  );
  const hasProvenance =
    tone !== null ||
    template !== null ||
    briefingDocuments.length > 0 ||
    publishableAssets.length > 0;

  /** Renders the wrapped link list for one document group. */
  const documentLinks = (group: DraftDocumentSnapshot[]) => (
    <span className="flex flex-wrap gap-x-2 gap-y-0.5">
      {group.map((doc) => {
        const KindIcon = documentKindIcon(doc);
        return (
          <button
            key={doc.id}
            type="button"
            className="inline-flex cursor-pointer items-center gap-1 text-blue-600 underline-offset-2 hover:underline"
            onClick={(event) => {
              // Stop propagation so opening a document does not trigger onOpen.
              event.stopPropagation();
              setSelectedDocument(doc);
            }}
          >
            <KindIcon
              size={12}
              className="shrink-0 text-gray-400"
              aria-hidden="true"
            />
            {doc.title}
          </button>
        );
      })}
    </span>
  );

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

            {/* Provenance table: label column with icons, value column. */}
            {hasProvenance && (
              <div className="mt-2 grid grid-cols-[auto_1fr] items-start gap-x-3 gap-y-1 text-xs">
                {tone && (
                  <ProvenanceRow icon={Megaphone} label="Tone">
                    {tone.label}
                  </ProvenanceRow>
                )}
                {template && (
                  <ProvenanceRow icon={LayoutTemplate} label="Template">
                    {template.label}
                  </ProvenanceRow>
                )}
                {briefingDocuments.length > 0 && (
                  <ProvenanceRow icon={FileText} label="Briefing documents">
                    {documentLinks(briefingDocuments)}
                  </ProvenanceRow>
                )}
                {publishableAssets.length > 0 && (
                  <ProvenanceRow icon={Image} label="Publishable assets">
                    {documentLinks(publishableAssets)}
                  </ProvenanceRow>
                )}
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
