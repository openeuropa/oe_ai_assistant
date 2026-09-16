/**
 * DocumentDetailsDialog component.
 *
 * Shows the full detail of a DraftDocumentSnapshot in a controlled dialog:
 * the document kind in the header, then the title, the file type and size,
 * and the summary produced by the extraction pipeline. Controlled by the
 * parent: the dialog is visible when `document` is non-null and hides when
 * `onClose` is called.
 */

import { Dialog } from "@/components/ui/dialog";
import { formatFileSize } from "@/lib/format-file-size";
import type { DraftDocumentSnapshot } from "../draft-result";

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
 * "context" documents are briefing material; any other value is shown
 * as-is.
 */
function categoryHeaderLabel(category: string): string {
  return category === "context" ? "Context document" : category;
}

/**
 * Returns the file details line: the uppercase type and the size.
 *
 * The type is the lowercase extension the backend derived from the stored
 * file name; "file" means the extension is unknown and is left out.
 */
function fileDetails(document: DraftDocumentSnapshot): string {
  return [
    document.meta.type === "file" ? "" : document.meta.type.toUpperCase(),
    formatFileSize(document.meta.size),
  ]
    .filter((part) => part !== "")
    .join(" - ");
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

          {/* File type and size line. */}
          <p className="mt-1 text-xs text-gray-400">{fileDetails(document)}</p>

          {/* Summary paragraph with fallback. */}
          <p className="mt-2 text-sm text-gray-700">
            {document.summary !== ""
              ? document.summary
              : "No summary captured."}
          </p>
        </div>
      )}
    </Dialog>
  );
}
