/**
 * DocumentDetailsDialog component.
 *
 * Shows the full detail of a DraftDocumentSnapshot in a controlled dialog.
 * Controlled by the parent: the dialog is visible when `document` is non-null
 * and hides when `onClose` is called.
 */

import { Dialog } from "@/components/ui/dialog";
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
 * Returns a human-readable badge label for a document category.
 *
 * "context" maps to "Briefing", "publishable" maps to "Publishable", and
 * any other value is returned as-is.
 */
export function categoryBadgeLabel(category: string): string {
  if (category === "context") return "Briefing";
  if (category === "publishable") return "Publishable";
  return category;
}

/**
 * Renders document meta as a string or as "key: value" lines when it is
 * an object. Returns null when meta is absent or of an unexpected type.
 */
function MetaBlock({ meta }: { meta: unknown }) {
  if (meta === null || meta === undefined) {
    return null;
  }

  if (typeof meta === "string") {
    return <p className="mt-3 text-xs text-gray-400">{meta}</p>;
  }

  if (typeof meta === "object" && !Array.isArray(meta)) {
    const entries = Object.entries(meta as Record<string, unknown>);
    if (entries.length === 0) return null;

    return (
      <dl className="mt-3 space-y-0.5 text-xs text-gray-400">
        {entries.map(([key, value]) => (
          <div key={key}>
            <span className="font-medium">{key}:</span> {String(value)}
          </div>
        ))}
      </dl>
    );
  }

  return null;
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
      title={document?.title ?? "Document details"}
    >
      {document && (
        <div>
          {/* Category badge. */}
          <span className="inline-block rounded-full border border-gray-200 bg-gray-50 px-2.5 py-0.5 text-xs text-gray-500">
            {categoryBadgeLabel(document.category)}
          </span>

          {/* Summary paragraph with fallback. */}
          <p className="mt-3 text-sm text-gray-700">
            {document.summary ?? "No summary captured."}
          </p>

          {/* Optional meta block. */}
          <MetaBlock meta={document.meta} />
        </div>
      )}
    </Dialog>
  );
}
