/**
 * DocumentDetailsDialog component.
 *
 * Shows the full detail of a DraftDocumentSnapshot in a controlled dialog:
 * the document kind in the header, then the title and the extracted summary,
 * and a download control opening the file in a new tab when the descriptor
 * carries one. Controlled by the parent: the dialog is visible when
 * `document` is non-null and hides when `onClose` is called.
 */

import { Download } from "lucide-react";
import { Dialog } from "@/components/ui/dialog";
import { formatFileSize } from "@/lib/format-file-size";
import type { DraftDocumentFile, DraftDocumentSnapshot } from "../draft-result";

/** Props for DocumentDetailsDialog. */
export interface DocumentDetailsDialogProps {
  /**
   * The document to display. Pass null to hide the dialog (controlled
   * open/close).
   */
  document: DraftDocumentSnapshot | null;
  /** Called when the user requests the dialog to close. */
  onClose: () => void;
}

/**
 * Returns the dialog header label for a document category.
 *
 * "context" documents are briefing material, "publishable" documents are
 * assets placed into the content; any other value is shown as-is.
 */
function categoryHeaderLabel(category: string): string {
  if (category === "context") return "Briefing document";
  if (category === "publishable") return "Publishable asset";
  return category;
}

/**
 * Returns a short uppercase type label for a file.
 *
 * Prefers the file name extension ("report.docx" gives "DOCX") because mime
 * subtypes of office formats are unreadably long; falls back to the mime
 * subtype when it is short, or an empty string when nothing usable exists.
 */
function fileTypeLabel(file: DraftDocumentFile): string {
  const extension = file.name.includes(".")
    ? file.name.split(".").pop()
    : undefined;
  if (extension) {
    return extension.toUpperCase();
  }
  const subtype = file.mime?.split("/")[1];
  if (subtype && subtype.length <= 5) {
    return subtype.toUpperCase();
  }
  return "";
}

/**
 * Download control for the document's file, opening it in a new tab.
 *
 * Shows the file name with its type and size so the editor knows what the
 * download is before clicking.
 */
function DownloadLink({ file }: { file: DraftDocumentFile }) {
  const details = [
    fileTypeLabel(file),
    file.size !== undefined ? formatFileSize(file.size) : "",
  ]
    .filter((part) => part !== "")
    .join(" - ");

  return (
    <a
      href={file.url}
      target="_blank"
      rel="noopener noreferrer"
      className="mt-4 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50"
    >
      <Download size={15} className="shrink-0 text-gray-400" />
      <span className="min-w-0">
        <span className="block truncate font-medium">{file.name}</span>
        {details && (
          <span className="block text-xs text-gray-400">{details}</span>
        )}
      </span>
    </a>
  );
}

/**
 * Controlled dialog that displays the details of a single draft document.
 *
 * Designed to be opened from DraftCard when the editor clicks a document
 * link. The selected document state lives in DraftCard.
 */
export function DocumentDetailsDialog({
  document,
  onClose,
}: DocumentDetailsDialogProps) {
  return (
    <Dialog
      open={document !== null}
      onClose={onClose}
      title={document ? categoryHeaderLabel(document.category) : "Document"}
      description={document?.title}
    >
      {document && (
        <div>
          {/* Document title below the kind header. */}
          <h3 className="text-sm font-semibold text-gray-900">
            {document.title}
          </h3>

          {/* Summary paragraph with fallback. */}
          <p className="mt-2 text-sm text-gray-700">
            {document.summary ?? "No summary captured."}
          </p>

          {/* Download control, once the descriptor carries a file. */}
          {document.file && <DownloadLink file={document.file} />}
        </div>
      )}
    </Dialog>
  );
}
