import { FileText, Upload, X } from "lucide-react";
import { useRef } from "react";
import { Pane } from "@/components/ui/pane";
import type { DraftingDocument } from "../hooks/use-drafting-documents";

export interface DocumentsPanelProps {
  /** Documents attached to ground the next draft. */
  selected: DraftingDocument[];
  /** Removes a document from the list. */
  onRemove: (id: string) => void;
  /** Handles files chosen from the upload control. */
  onUpload: (files: FileList | null) => void;
  onSave: () => Promise<void>;
  /** Closes the panel. */
  onCancel: () => void;
  isSaving?: boolean;
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
        {/* Upload control. */}
        <button
          type="button"
          className="block w-full cursor-pointer rounded-lg border border-dashed border-gray-300 bg-white p-4 text-center hover:border-blue-300 hover:bg-blue-50"
          onClick={() => fileInputRef.current?.click()}
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
            onUpload(event.target.files);
            event.target.value = "";
          }}
        />

        {/* Attached documents, two per row to save space. */}
        <div className="grid gap-2 md:grid-cols-2">
          {selected.map((document) => (
            <div
              key={document.id}
              className="flex items-center justify-between gap-3 rounded-md border border-blue-100 bg-blue-50 px-3 py-2"
            >
              <div className="min-w-0">
                <p className="truncate text-xs font-medium text-gray-900">
                  {document.title}
                </p>
                <p className="text-xs text-gray-500">{document.meta}</p>
              </div>
              <button
                type="button"
                className="cursor-pointer rounded-md p-1 text-gray-400 hover:bg-white hover:text-gray-600"
                aria-label={`Remove ${document.title}`}
                onClick={() => onRemove(document.id)}
              >
                <X size={14} />
              </button>
            </div>
          ))}
        </div>
      </div>
    </Pane>
  );
}
