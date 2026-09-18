import { Loader2 } from "lucide-react";
import {
  type DocumentStatusTone,
  describeDocumentStatus,
} from "../document-status";
import type { DraftingDocumentStatus } from "../types";

/** Tailwind classes per badge tone. */
const TONE_CLASSES: Record<DocumentStatusTone, string> = {
  neutral: "bg-gray-100 text-gray-700",
  progress: "bg-blue-100 text-blue-700",
  success: "bg-green-100 text-green-700",
  danger: "bg-red-100 text-red-700",
};

/**
 * Small pill showing where a document is in the extraction pipeline.
 *
 * In-flight states show a spinner so the editor knows the server is busy.
 */
export function DocumentStatusBadge({
  status,
}: {
  status: DraftingDocumentStatus;
}) {
  const description = describeDocumentStatus(status);
  return (
    <span
      data-testid="document-status"
      data-status={status}
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_CLASSES[description.tone]}`}
    >
      {description.busy && <Loader2 size={10} className="animate-spin" />}
      {description.label}
    </span>
  );
}
