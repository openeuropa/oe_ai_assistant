import { FileText, Upload, X } from "lucide-react";
import { useRef } from "react";
import { Pane } from "@/components/ui/pane";
import { formatFileSize } from "@/lib/format-file-size";
import type { DraftingDocument } from "../hooks/use-drafting-documents";

export interface DocumentsPanelProps {
  /** Documents attached to ground the next draft. */
  selected: DraftingDocument[];
  /** Removes a document from the list. */
  onRemove: (id: string) => void | Promise<void>;
  /** Handles files chosen from the upload control. */
  onUpload: (files: FileList | null) => void | Promise<void>;
  onSave: () => Promise<void>;
  /** Closes the panel. */
  onCancel: () => void;
  isSaving?: boolean;
  error?: string | null;
}

function formatExtractionStatus(status: DraftingDocument["extractionStatus"]) {
  switch (status) {
    case "pending":
      return "Extraction pending";
    case "processing":
      return "Extracting text";
    case "completed":
      return "Extraction complete";
    case "failed":
      return "Extraction failed";
    default:
      return null;
  }
}

function DocumentContextDetails({ document }: { document: DraftingDocument }) {
  const summary = document.summary?.trim();
  const status = formatExtractionStatus(document.extractionStatus);

  if (summary) {
    return <p className="line-clamp-2 text-xs text-gray-600">{summary}</p>;
  }

  if (status) {
    return <p className="text-xs font-medium text-blue-700">{status}</p>;
  }

  return null;
}

/**
 * Reference documents pane.
 *
 * Composes the shared Pane chrome with an upload control and the list of
 * attached documents. Presentational only; the document list and mutations
 * live in useDraftingDocuments.
 */
export function DocumentsPanel({
  selected,
  onRemove,
  onUpload,
  onSave,
  onCancel,
  isSaving = false,
  error = null,
}: DocumentsPanelProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);

  return (
    <Pane
      icon={<FileText size={18} />}
      title="Documents"
      description="Attach or select documents that should guide the next draft."
      onSave={onSave}
      onCancel={onCancel}
      isSaving={isSaving}
    >
      <div className="space-y-4 text-sm text-gray-700">
        <p className="text-xs font-medium uppercase text-blue-700">
          Temporary briefing context
        </p>

        {/* Upload control. */}
        <button
          type="button"
          className="block w-full cursor-pointer rounded-lg border border-dashed border-gray-300 bg-white p-4 text-center hover:border-blue-300 hover:bg-blue-50"
          onClick={() => fileInputRef.current?.click()}
          disabled={isSaving}
        >
          <Upload size={18} className="mx-auto mb-2 text-gray-400" />
          <p className="text-xs font-medium text-gray-700">
            Drop files here or browse your computer
          </p>
          <p className="mt-1 text-xs text-gray-500">
            PDF, DOCX, TXT, or Markdown files
          </p>
        </button>
        <input
          ref={fileInputRef}
          type="file"
          multiple
          className="sr-only"
          accept=".pdf,.doc,.docx,.txt,.md,text/plain,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
          onChange={(event) => {
            void Promise.resolve(onUpload(event.target.files)).catch(() => {});
            event.target.value = "";
          }}
        />

        {error && (
          <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
            {error}
          </p>
        )}

        {/* Attached documents, two per row to save space. */}
        {selected.length > 0 ? (
          <div className="grid gap-2 md:grid-cols-2">
            {selected.map((document) => (
              <div
                key={document.id}
                className="flex items-start justify-between gap-3 rounded-md border border-blue-100 bg-blue-50 px-3 py-2"
              >
                <div className="min-w-0 space-y-1">
                  <p className="truncate text-xs font-medium text-gray-900">
                    {document.title}
                  </p>
                  <p className="text-xs text-gray-500">
                    {document.meta.type.toUpperCase()} -{" "}
                    {formatFileSize(document.meta.size)}
                  </p>
                  <DocumentContextDetails document={document} />
                </div>
                <button
                  type="button"
                  className="cursor-pointer rounded-md p-1 text-gray-400 hover:bg-white hover:text-gray-600"
                  aria-label={`Remove ${document.title}`}
                  onClick={() => {
                    void Promise.resolve(onRemove(document.id)).catch(() => {});
                  }}
                  disabled={isSaving}
                >
                  <X size={14} />
                </button>
              </div>
            ))}
          </div>
        ) : (
          <p className="rounded-md border border-gray-200 bg-white px-3 py-2 text-xs text-gray-600">
            No temporary context documents are attached to this drafting
            session.
          </p>
        )}
      </div>
    </Pane>
  );
}
